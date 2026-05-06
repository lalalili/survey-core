<?php

namespace Lalalili\SurveyCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
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
use Lalalili\SurveyCore\Http\Resources\PublicSurveyResource;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\ConditionGroupEvaluator;

class PublicSurveyController extends Controller
{
    public function show(string $publicKey, ResolveSurveyTokenAction $resolveToken): Response|JsonResponse
    {
        $survey = Survey::with([
            'pages'  => fn ($q) => $q->orderBy('sort_order'),
            'fields' => fn ($q) => $q->orderBy('sort_order'),
            'theme',
            'calculations',
        ])->where('public_key', $publicKey)->firstOrFail();

        if ($view = $this->availabilityView($survey)) {
            return $view;
        }

        $resolved = null;

        if ($rawToken = request()->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
                SurveyTokenResolved::dispatch($resolved->token, $resolved->recipient);
            } catch (InvalidSurveyTokenException) {
                abort(403, '連結無效或已過期。');
            }
        }

        if ($this->requiresPersonalizedToken($survey) && ! $resolved) {
            abort(403, '此問卷需要使用個人化連結填寫。');
        }

        if ($view = $this->duplicateView($survey, request(), $resolved)) {
            return $view;
        }

        SurveyViewed::dispatch($survey, $resolved?->recipient);

        $theme = $survey->resolvedThemeTokens();
        $optionUsage = $this->optionUsage($survey);

        return response()->view('survey-core::survey.show', compact('survey', 'resolved', 'theme', 'optionUsage'));
    }

    public function submit(
        string $publicKey,
        SubmitSurveyRequest $request,
        ResolveSurveyTokenAction $resolveToken,
        SubmitSurveyResponseAction $submitResponse,
    ): JsonResponse {
        $survey = Survey::with(['fields', 'pages', 'calculations'])->where('public_key', $publicKey)->firstOrFail();

        if (! $survey->isAcceptingSubmissions()) {
            return response()->json(['message' => 'Survey is not currently accepting submissions.'], 403);
        }

        if (! $survey->hasQuotaAvailable()) {
            return response()->json(['message' => $survey->quota_message ?: '問卷已額滿。'], 403);
        }

        $resolved = null;

        if ($rawToken = $request->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
            } catch (InvalidSurveyTokenException $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
        }

        if ($this->requiresPersonalizedToken($survey) && ! $resolved) {
            return response()->json(['message' => '此問卷需要使用個人化連結填寫。'], 403);
        }

        if ($this->hasDuplicateSubmission($survey, $request, $resolved)) {
            return response()->json(['message' => $survey->uniqueness_message ?: '您已填寫過此問卷。'], 403);
        }

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
                ],
            );
        } catch (SurveyNotAvailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (SurveyValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->getErrors()], 422);
        }

        $jsonResponse = response()->json([
            'message'     => $this->thankYouMessage($survey, $response->calculations_json ?? []),
            'thank_you_page_id' => $this->thankYouPage($survey, $response->calculations_json ?? [])?->page_key,
            'response_id' => $response->id,
            'calculations' => $response->calculations_json ?? [],
        ], 201);

        if ($survey->uniqueness_mode === SurveyUniquenessMode::Cookie) {
            $jsonResponse->cookie($this->duplicateCookieName($survey), '1', 60 * 24 * 365);
        }

        return $jsonResponse;
    }

    public function upload(string $publicKey, Request $request): JsonResponse
    {
        $survey = Survey::with('fields')->where('public_key', $publicKey)->firstOrFail();

        if (! $survey->isAcceptingSubmissions()) {
            return response()->json(['message' => 'Survey is not currently accepting submissions.'], 403);
        }

        $fieldKey = (string) $request->input('field_key');
        $field = $survey->fields->first(
            fn (SurveyField $candidate): bool => $candidate->field_key === $fieldKey && $candidate->type === SurveyFieldType::FileUpload,
        );

        if (! $field instanceof SurveyField) {
            return response()->json(['message' => 'Invalid file upload field.'], 422);
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
            'filename' => $media->file_name,
            'size' => $media->size,
        ], 201);
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
            ?? 'Thank you for your submission.';

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

    private function duplicateView(Survey $survey, Request $request, mixed $resolved): ?Response
    {
        if (! $this->hasDuplicateSubmission($survey, $request, $resolved)) {
            return null;
        }

        $theme = $survey->resolvedThemeTokens();

        return response()->view('survey-core::survey.already_submitted', compact('survey', 'theme'));
    }

    private function hasDuplicateSubmission(Survey $survey, Request $request, mixed $resolved = null): bool
    {
        return match ($survey->uniqueness_mode) {
            SurveyUniquenessMode::None => false,
            SurveyUniquenessMode::Token => $resolved?->token?->max_submissions !== null && $resolved?->token?->used_count >= $resolved?->token?->max_submissions,
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

    private function duplicateCookieName(Survey $survey): string
    {
        return 'survey_dup_'.$survey->public_key;
    }

    private function requiresPersonalizedToken(Survey $survey): bool
    {
        $personalization = $survey->settings_json['personalization'] ?? [];

        return ! empty($personalization['audience_list_id'])
            && (bool) ($personalization['required'] ?? true);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function optionUsage(Survey $survey): array
    {
        $usage = [];

        foreach ($survey->fields as $field) {
            if (! $field->type->requiresOptions()) {
                continue;
            }

            foreach ($field->normalizedOptions() as $option) {
                $usage[$field->field_key][$option['value']] = SurveyAnswer::query()
                    ->where('survey_field_id', $field->id)
                    ->where(function ($query) use ($option): void {
                        $query->where('answer_text', $option['value'])
                            ->orWhereJsonContains('answer_json', $option['value']);
                    })
                    ->count();
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
        $thankYouPages = $survey->pages->filter(fn ($page): bool => ($page->kind?->value ?? 'question') === 'thank_you');

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
