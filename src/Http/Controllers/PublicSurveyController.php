<?php

namespace Lalalili\SurveyCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Actions\RecordSurveyResponseEventAction;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\ResolvedToken;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;
use Lalalili\SurveyCore\Events\SurveyTokenResolved;
use Lalalili\SurveyCore\Events\SurveyViewed;
use Lalalili\SurveyCore\Exceptions\InvalidSurveyTokenException;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Requests\SubmitSurveyRequest;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseConsent;
use Lalalili\SurveyCore\Models\SurveyToken;
use Lalalili\SurveyCore\Services\TurnstileVerifier;
use Lalalili\SurveyCore\Support\ConditionGroupEvaluator;
use Lalalili\SurveyCore\Support\SurveyFileUploadToken;
use Lalalili\SurveyCore\Support\SurveyOptionUsageCounter;
use Throwable;

class PublicSurveyController extends Controller
{
    public function show(string $publicKey, ResolveSurveyTokenAction $resolveToken): Response|JsonResponse
    {
        // pages/fields 關聯已內建 orderBy(sort_order)；SQL Server 不允許 ORDER BY 重複欄位，
        // 呼叫端不可再排一次。
        $survey = Survey::with([
            'pages',
            'fields',
            'theme',
            'calculations',
        ])->where('public_key', $publicKey)->firstOrFail();

        return $this->renderSurvey($survey, request(), $resolveToken);
    }

    public function showCollector(string $collectorSlug, ResolveSurveyTokenAction $resolveToken): Response|JsonResponse
    {
        $collector = SurveyCollector::with([
            'survey.pages',
            'survey.fields',
            'survey.theme',
            'survey.calculations',
        ])->where('slug', $collectorSlug)->firstOrFail();

        abort_unless($collector->isActive(), 404);

        return $this->renderSurvey($collector->survey, request(), $resolveToken, $collector);
    }

    public function unlock(string $publicKey, Request $request): JsonResponse
    {
        $survey = Survey::where('public_key', $publicKey)->firstOrFail();

        return $this->unlockSurvey($survey, $request);
    }

    public function unlockCollector(string $collectorSlug, Request $request): JsonResponse
    {
        $collector = SurveyCollector::with('survey')->where('slug', $collectorSlug)->firstOrFail();

        abort_unless($collector->isActive(), 404);

        return $this->unlockSurvey($collector->survey, $request);
    }

    public function event(
        string $publicKey,
        Request $request,
        ResolveSurveyTokenAction $resolveToken,
        RecordSurveyResponseEventAction $recordEvent,
    ): JsonResponse {
        $survey = Survey::where('public_key', $publicKey)->firstOrFail();
        $resolved = null;

        if ($rawToken = $request->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
            } catch (InvalidSurveyTokenException) {
                $resolved = null;
            }
        }

        if ($this->requiresToken($survey) && ! $resolved) {
            return response()->json(['message' => '此問卷需要使用個性化連結填寫。'], 403);
        }

        if ($this->requiresPassword($survey) && ! $this->hasUnlockedPassword($survey, $request)) {
            return response()->json(['message' => '此問卷需要密碼。'], 403);
        }

        $validated = $request->validate([
            'event' => ['required', 'string', 'in:viewed,started,page_viewed,submitted,abandoned'],
            'page_key' => ['nullable', 'string', 'max:120'],
            'response_id' => ['nullable', 'integer'],
            'collector' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ]);

        $collector = $this->resolveCollector($survey, $request);
        $response = null;

        if (! empty($validated['response_id'])) {
            $response = SurveyResponse::query()
                ->where('survey_id', $survey->id)
                ->whereKey((int) $validated['response_id'])
                ->first();
        }

        $recordEvent->execute(
            survey: $survey,
            event: (string) $validated['event'],
            collector: $collector,
            response: $response,
            recipient: $resolved?->recipient,
            pageKey: $validated['page_key'] ?? null,
            metadata: array_merge((array) ($validated['metadata'] ?? []), [
                'token_present' => $request->query('t') !== null,
            ]),
        );

        return response()->json(['recorded' => true]);
    }

    private function renderSurvey(
        Survey $survey,
        Request $request,
        ResolveSurveyTokenAction $resolveToken,
        ?SurveyCollector $collector = null,
    ): Response {
        if ($view = $this->availabilityView($survey)) {
            return $view;
        }

        $resolved = null;

        if ($rawToken = $request->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
                SurveyTokenResolved::dispatch($resolved->token, $resolved->recipient);
            } catch (InvalidSurveyTokenException) {
                $tokenRecord = SurveyToken::where('token', $rawToken)->first();
                if ($tokenRecord?->isExpired()) {
                    $theme = $survey->resolvedThemeTokens();

                    return response()->view('survey-core::survey.token_expired', compact('survey', 'theme'), 403);
                }

                if ($tokenRecord && $this->isMarketingActivityDuplicateToken($survey, $tokenRecord)) {
                    return $this->alreadySubmittedView($survey);
                }

                abort(403, '連結無效或已過期。');
            }
        }

        if ($this->requiresToken($survey) && ! $resolved) {
            abort(403, '此問卷需要使用個性化連結填寫。');
        }

        if ($view = $this->duplicateView($survey, $request, $resolved)) {
            return $view;
        }

        SurveyViewed::dispatch($survey, $resolved?->recipient);

        $theme = $survey->resolvedThemeTokens();
        $optionUsage = $this->optionUsage($survey);
        $passwordUnlocked = ! $this->requiresPassword($survey) || $this->hasUnlockedPassword($survey, $request);
        // Per-respondent seed so randomized option/row order stays stable across
        // page navigation and submit re-renders for the same visitor. Falls back
        // to the client IP when no session is available (e.g. stateless requests).
        $seedSource = $request->hasSession() ? $request->session()->getId() : (string) $request->ip();
        // (selection_based 重複核選題：選項在前端依來源題的勾選即時產生，
        // 故公開頁僅輸出容器與來源 field_key，由 show.blade 的 JS 動態填入。)
        $shuffleSeed = (int) crc32($seedSource.'|'.$survey->id);

        return response()->view('survey-core::survey.show', compact('survey', 'resolved', 'theme', 'optionUsage', 'collector', 'passwordUnlocked', 'shuffleSeed'));
    }

    public function submit(
        string $publicKey,
        SubmitSurveyRequest $request,
        ResolveSurveyTokenAction $resolveToken,
        SubmitSurveyResponseAction $submitResponse,
        TurnstileVerifier $turnstile,
        RecordSurveyResponseEventAction $recordEvent,
    ): JsonResponse {
        $survey = Survey::with(['fields', 'pages', 'calculations'])->where('public_key', $publicKey)->firstOrFail();

        if (! $survey->isAcceptingSubmissions()) {
            return response()->json(['message' => '此問卷目前未開放填寫。'], 403);
        }

        if (! $survey->hasQuotaAvailable()) {
            return response()->json(['message' => $survey->quota_message ?: '問卷已額滿。'], 403);
        }

        $resolved = null;

        if ($rawToken = $request->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
            } catch (InvalidSurveyTokenException $e) {
                $tokenRecord = SurveyToken::where('token', $rawToken)->first();

                if ($tokenRecord && $this->isMarketingActivityDuplicateToken($survey, $tokenRecord)) {
                    return response()->json(['message' => $survey->uniqueness_message ?: '您已填寫過此問卷。'], 403);
                }

                return response()->json(['message' => $e->getMessage()], 403);
            }
        }

        if ($this->requiresToken($survey) && ! $resolved) {
            return response()->json(['message' => '此問卷需要使用個性化連結填寫。'], 403);
        }

        if ($this->requiresPassword($survey) && ! $this->hasUnlockedPassword($survey, $request)) {
            return response()->json(['message' => '此問卷需要密碼。'], 403);
        }

        if ($message = $this->securityValidationMessage($survey, $request, $turnstile)) {
            return response()->json(['message' => $message], 422);
        }

        if ($this->hasDuplicateSubmission($survey, $request, $resolved)) {
            return response()->json(['message' => $survey->uniqueness_message ?: '您已填寫過此問卷。'], 403);
        }

        $collector = $this->resolveCollector($survey, $request);

        $payload = new SubmissionPayload(
            visibleAnswers: $request->answers(),
            tokenContext: $resolved,
        );

        try {
            $response = $submitResponse->execute(
                $survey,
                $payload,
                ip: $request->ip() ?? '',
                userAgent: $request->userAgent() ?? '',
                qualityContext: [
                    'elapsed_ms' => $request->integer('_elapsed_ms') ?: null,
                    'honeypot_hit' => filled($request->input('_hp')),
                    'is_anomaly_duplicate' => $this->hasAnomalyDuplicate($survey, $request),
                ],
                collector: $collector,
            );
        } catch (SurveyNotAvailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (SurveyValidationException $e) {
            return response()->json(['message' => '填答內容有誤，請依提示修正。', 'errors' => $e->getErrors()], 422);
        }

        $this->recordConsent($survey, $response, $request);

        $recordEvent->execute(survey: $survey, event: 'submitted', collector: $collector, response: $response, metadata: [
            'elapsed_ms' => $request->integer('_elapsed_ms') ?: null,
        ]);

        $jsonResponse = response()->json([
            'message' => $this->thankYouMessage($survey, $response->calculations_json ?? []),
            'thank_you_page_id' => $this->thankYouPage($survey, $response->calculations_json ?? [])?->page_key,
            'response_id' => $response->id,
            'response_number' => $response->response_number,
            'calculations' => $response->calculations_json ?? [],
        ], 201);

        if ($survey->uniqueness_mode === SurveyUniquenessMode::Cookie) {
            $jsonResponse->cookie($this->duplicateCookieName($survey), '1', 60 * 24 * 365);
        }

        $anomalyMode = (string) data_get($survey->settings_json, 'anomaly.detect_duplicate', 'none');
        if (in_array($anomalyMode, ['cookie', 'both'], true)) {
            $jsonResponse->cookie('survey_anomaly_dup_'.$survey->public_key, '1', 60 * 24 * 365);
        }

        return $jsonResponse;
    }

    public function upload(string $publicKey, Request $request, SurveyFileUploadToken $uploadToken): JsonResponse
    {
        $survey = Survey::with('fields')->where('public_key', $publicKey)->firstOrFail();

        if (! $survey->isAcceptingSubmissions()) {
            return response()->json(['message' => '此問卷目前未開放填寫。'], 403);
        }

        if ($this->requiresPassword($survey) && ! $this->hasUnlockedPassword($survey, $request)) {
            return response()->json(['message' => '此問卷需要密碼。'], 403);
        }

        $fieldKey = (string) $request->input('field_key');
        $field = $survey->fields->first(
            fn (SurveyField $candidate): bool => $candidate->field_key === $fieldKey && $candidate->type === SurveyFieldType::FileUpload,
        );

        if (! $field instanceof SurveyField) {
            return response()->json(['message' => '上傳欄位不正確，請重新選擇檔案。'], 422);
        }

        $fileRules = ['required', 'file'];
        $allowedMimes = array_values(array_filter((array) ($field->settings_json['allowed_mimes'] ?? [])));
        if ($allowedMimes !== []) {
            $fileRules[] = 'mimes:'.implode(',', $allowedMimes);
        }

        $maxSizeMb = (int) ($field->settings_json['max_size_mb'] ?? 0);
        if ($maxSizeMb > 0) {
            $fileRules[] = 'max:'.($maxSizeMb * 1024);
        }

        $validated = $request->validate([
            'field_key' => ['required', 'string'],
            'file' => $fileRules,
        ]);

        try {
            $draftResponse = SurveyResponse::create([
                'survey_id' => $survey->id,
                'completion_status' => SurveyResponseCompletionStatus::Partial,
            ]);

            $media = $draftResponse
                ->addMedia($validated['file'])
                ->withCustomProperties(['survey_field_key' => $field->field_key])
                ->toMediaCollection('survey_files');

            return response()->json([
                'media_id' => $media->id,
                'upload_token' => $uploadToken->issue($media, $draftResponse, $field),
                'filename' => $media->file_name,
                'size' => $media->size,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => '檔案上傳失敗，請稍後再試。'], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $calculations
     */
    private function thankYouMessage(Survey $survey, array $calculations): string
    {
        $survey->loadMissing('pages');

        $thankYouPage = $this->thankYouPage($survey, $calculations);
        $message = $thankYouPage?->settings_json['thank_you']['message']
            ?? $survey->submit_success_message
            ?? '感謝您的填寫！';

        return preg_replace_callback('/\{\{\s*calc\.([A-Za-z0-9_\-]+)\s*\}\}/', function (array $matches) use ($calculations): string {
            return (string) ($calculations[$matches[1]] ?? '');
        }, (string) $message) ?? (string) $message;
    }

    private function availabilityView(Survey $survey): ?Response
    {
        $theme = $survey->resolvedThemeTokens();

        if ($survey->status !== SurveyStatus::Published) {
            return response()->view('survey-core::survey.closed', compact('survey', 'theme'));
        }

        if ($survey->starts_at && now()->lt($survey->starts_at)) {
            return response()->view('survey-core::survey.not_started', compact('survey', 'theme'));
        }

        if ($survey->ends_at && now()->gt($survey->ends_at)) {
            return response()->view('survey-core::survey.closed', compact('survey', 'theme'));
        }

        if (! $survey->hasQuotaAvailable()) {
            return response()->view('survey-core::survey.quota_full', compact('survey', 'theme'));
        }

        return null;
    }

    private function duplicateView(Survey $survey, Request $request, ?ResolvedToken $resolved): ?Response
    {
        if (! $this->hasDuplicateSubmission($survey, $request, $resolved)) {
            return null;
        }

        return $this->alreadySubmittedView($survey);
    }

    private function alreadySubmittedView(Survey $survey): Response
    {
        $theme = $survey->resolvedThemeTokens();

        return response()->view('survey-core::survey.already_submitted', compact('survey', 'theme'));
    }

    private function hasDuplicateSubmission(Survey $survey, Request $request, ?ResolvedToken $resolved = null): bool
    {
        $uniquenessMode = $survey->uniqueness_mode ?? SurveyUniquenessMode::None;

        if ($this->isMarketingActivityDuplicateToken($survey, $resolved?->token)) {
            return true;
        }

        return match ($uniquenessMode) {
            SurveyUniquenessMode::None => false,
            SurveyUniquenessMode::Token => $resolved !== null
                && $resolved->token->max_submissions !== null
                && $resolved->token->used_count >= $resolved->token->max_submissions,
            SurveyUniquenessMode::Email => filled($resolved?->recipient?->email)
                && $survey->responses()
                    ->whereHas('recipient', fn ($query) => $query->where('email', $resolved->recipient->email))
                    ->whereNotNull('submitted_at')
                    ->exists(),
            SurveyUniquenessMode::Ip => filled($request->ip())
                && $survey->responses()
                    ->where('ip', $request->ip())
                    ->whereNotNull('submitted_at')
                    ->exists(),
            SurveyUniquenessMode::Cookie => $request->cookies->has($this->duplicateCookieName($survey)),
        };
    }

    private function isMarketingActivityDuplicateToken(Survey $survey, ?SurveyToken $token): bool
    {
        if (! $token || ! $token->survey_recipient_id) {
            return false;
        }

        $shortLinkClass = 'Lalalili\\MarketingAutomation\\Models\\ActivityShortLink';

        if (! class_exists($shortLinkClass)) {
            return false;
        }

        if (! Schema::hasTable('activity_short_links')) {
            return false;
        }

        $hasMarketingShortLink = $shortLinkClass::query()
            ->where(function ($query) use ($token): void {
                $query->where('survey_token_id', $token->id)
                    ->orWhere('original_url', 'like', '%t='.$token->token.'%');
            })
            ->exists();

        if (! $hasMarketingShortLink) {
            return false;
        }

        return $survey->responses()
            ->where('survey_recipient_id', $token->survey_recipient_id)
            ->whereNotNull('submitted_at')
            ->exists();
    }

    private function duplicateCookieName(Survey $survey): string
    {
        return 'survey_dup_'.$survey->public_key;
    }

    private function hasAnomalyDuplicate(Survey $survey, Request $request): bool
    {
        $mode = (string) data_get($survey->settings_json, 'anomaly.detect_duplicate', 'none');

        if ($mode === 'none') {
            return false;
        }

        $cookieKey = 'survey_anomaly_dup_'.$survey->public_key;

        $byCookie = in_array($mode, ['cookie', 'both'], true)
            && $request->cookies->has($cookieKey);

        $byIp = in_array($mode, ['ip', 'both'], true)
            && filled($request->ip())
            && $survey->responses()
                ->where('ip', $request->ip())
                ->whereNotNull('submitted_at')
                ->exists();

        return $byCookie || $byIp;
    }

    private function requiresPersonalizedToken(Survey $survey): bool
    {
        $personalization = $survey->settings_json['personalization'] ?? [];

        return filled($personalization['audience_list_id'] ?? null);
    }

    private function requiresToken(Survey $survey): bool
    {
        return $this->requiresPersonalizedToken($survey);
    }

    private function requiresPassword(Survey $survey): bool
    {
        return filled($survey->settings_json['password'] ?? null);
    }

    private function hasUnlockedPassword(Survey $survey, Request $request): bool
    {
        if (is_string($request->input('_password')) && $this->passwordMatches($survey, (string) $request->input('_password'))) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        return (bool) $request->session()->get($this->passwordSessionKey($survey), false);
    }

    private function unlockSurvey(Survey $survey, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $this->passwordMatches($survey, (string) $validated['password'])) {
            return response()->json(['message' => '密碼不正確。'], 422);
        }

        $request->session()->put($this->passwordSessionKey($survey), true);

        return response()->json(['unlocked' => true]);
    }

    private function passwordSessionKey(Survey $survey): string
    {
        return 'survey-core.password.'.$survey->id;
    }

    private function passwordMatches(Survey $survey, string $password): bool
    {
        $stored = (string) ($survey->settings_json['password'] ?? '');

        if ($stored === '') {
            return true;
        }

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
            return Hash::check($password, $stored);
        }

        return hash_equals($stored, $password);
    }

    private function securityValidationMessage(Survey $survey, SubmitSurveyRequest $request, TurnstileVerifier $turnstile): ?string
    {
        $minSubmissionMs = (int) ($survey->settings_json['security']['min_submission_ms'] ?? config('survey-core.security.min_submission_ms', 0));

        if ($minSubmissionMs > 0 && $request->integer('_elapsed_ms') > 0 && $request->integer('_elapsed_ms') < $minSubmissionMs) {
            return '送出速度過快，請確認內容後再送出。';
        }

        if (filled($survey->settings_json['terms_text'] ?? null) && ! $request->boolean('_terms_accepted')) {
            return '請先同意條款後再送出。';
        }

        if (! empty($survey->settings_json['anomaly']['turnstile']) && ! $turnstile->verify($request->string('_turnstile_token')->toString(), $request->ip())) {
            return '人機驗證失敗，請重新驗證後再送出。';
        }

        return null;
    }

    private function resolveCollector(Survey $survey, Request $request): ?SurveyCollector
    {
        $slug = $request->query('collector') ?? $request->input('collector');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return SurveyCollector::query()
            ->where('survey_id', $survey->id)
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    private function recordConsent(Survey $survey, SurveyResponse $response, SubmitSurveyRequest $request): void
    {
        if (blank($survey->settings_json['terms_text'] ?? null)) {
            return;
        }

        SurveyResponseConsent::create([
            'survey_response_id' => $response->id,
            'type' => 'terms',
            'version' => $survey->settings_json['terms_version'] ?? null,
            'accepted_at' => now(),
            'metadata_json' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function optionUsage(Survey $survey): array
    {
        $usage = [];

        foreach ($survey->fields as $field) {
            if (! $field->type->supportsOptionCapacity()) {
                continue;
            }

            // 僅有設定容量上限的選項才需要使用量：view 只以 used 判斷是否額滿，
            // 無上限的選項永遠不會額滿。跳過可免去每次頁面載入對 30 萬列答案表的大量 COUNT。
            $capacityOptions = array_filter(
                $field->normalizedOptions(),
                fn (array $option): bool => $option['capacity'] !== null,
            );

            if ($capacityOptions === []) {
                continue;
            }

            $counts = SurveyOptionUsageCounter::count(
                $field,
                array_map(fn (array $option): string => (string) $option['value'], $capacityOptions),
            );

            foreach ($capacityOptions as $option) {
                $usage[$field->field_key][$option['value']] = $counts[(string) $option['value']] ?? 0;
            }
        }

        return $usage;
    }

    /**
     * @param  array<string, mixed>  $calculations
     */
    private function thankYouPage(Survey $survey, array $calculations): ?SurveyPage
    {
        $survey->loadMissing('pages');
        $thankYouPages = $survey->pages->filter(fn (SurveyPage $page): bool => $page->kind->value === 'thank_you');

        foreach (($survey->settings_json['thank_you_branches'] ?? []) as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $condition = $branch['condition'] ?? [];
            $pageId = $branch['page_id'] ?? data_get($branch, 'action.target_page_id');

            if (is_array($condition) && ConditionGroupEvaluator::passes($this->calculationConditionGroup($condition), $calculations)) {
                $target = $thankYouPages->first(fn ($page): bool => $page->page_key === $pageId);
                if ($target) {
                    return $target;
                }
            }
        }

        return $thankYouPages->first();
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function calculationConditionGroup(array $condition): array
    {
        if (isset($condition['calc_key'])) {
            return [
                'logic' => 'and',
                'conditions' => [[
                    'field_key' => (string) $condition['calc_key'],
                    'op' => (string) ($condition['op'] ?? 'equals'),
                    'value' => $condition['value'] ?? null,
                ]],
            ];
        }

        return $condition;
    }
}
