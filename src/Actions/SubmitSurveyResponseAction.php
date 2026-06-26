<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyToken;
use Lalalili\SurveyCore\Support\JumpLogicResolver;
use Lalalili\SurveyCore\Support\SurveyFileUploadToken;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SubmitSurveyResponseAction
{
    public function __construct(
        private readonly HydratePersonalizedFieldsAction $hydrateFields,
        private readonly ValidateSurveySubmissionAction $validateSubmission,
        private readonly CalculateSurveyResponseAction $calculateResponse,
        private readonly EvaluateResponseQualityAction $evaluateQuality,
        private readonly SurveyFileUploadToken $uploadToken,
    ) {}

    /**
     * @param  array{elapsed_ms?: int|null, honeypot_hit?: bool, ip?: string|null}  $qualityContext
     */
    public function execute(
        Survey $survey,
        SubmissionPayload $payload,
        string $ip = '',
        string $userAgent = '',
        array $qualityContext = [],
        ?SurveyCollector $collector = null,
    ): SurveyResponse {
        // Validate against visible fields only
        $this->validateSubmission->execute($survey, $payload->visibleAnswers, $payload->tokenContext);

        return DB::transaction(function () use ($survey, $payload, $ip, $userAgent, $qualityContext, $collector) {
            // 僅在有額度上限時序列化提交：用 advisory lock 取代 surveys 列鎖，
            // 既避免超賣，又不阻塞後台對該問卷的編輯。無上限的問卷完全免鎖。
            if ($survey->max_responses !== null) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['survey_quota_'.$survey->id]);
                }

                if (! $survey->refresh()->hasQuotaAvailable()) {
                    throw new SurveyNotAvailableException($survey->quota_message ?: '問卷已額滿。');
                }
            }

            $tokenContext = $payload->tokenContext;
            $lockedToken = $this->lockValidToken($survey, $tokenContext?->token);
            $recipient = $tokenContext?->recipient;

            if ($this->isMarketingActivityDuplicateSubmission($survey, $lockedToken?->id, $lockedToken?->token, $recipient?->id)) {
                throw new SurveyNotAvailableException($survey->uniqueness_message ?: '您已填寫過此問卷。');
            }

            // Hydrate server-side personalized hidden values
            $hiddenMap = $recipient
                ? $this->hydrateFields->execute($survey->fields, $recipient)
                : null;

            // Build final answer map:
            // 1. Start with visible answers
            // 2. Strip any hidden field keys the frontend may have smuggled in
            // 3. Strip answers from pages skipped by jump logic
            // 4. Merge server-resolved hidden values
            $visitedPages = JumpLogicResolver::resolveVisitedPages($survey, $payload->visibleAnswers);

            $hiddenKeys = $survey->fields
                ->filter(fn (SurveyField $f) => $f->is_hidden || $f->type->isContentBlock())
                ->pluck('field_key')
                ->all();

            $safeVisible = array_diff_key($payload->visibleAnswers, array_flip($hiddenKeys));

            // Strip answers from skipped pages (jump logic)
            if ($visitedPages !== null) {
                $skippedPageKeys = $survey->fields
                    ->filter(fn (SurveyField $f) => ! in_array($f->survey_page_id, $visitedPages, true))
                    ->pluck('field_key')
                    ->all();
                $safeVisible = array_diff_key($safeVisible, array_flip($skippedPageKeys));
            }

            // Strip answers for fields whose branching condition is not met
            $conditionallyHiddenKeys = $survey->fields
                ->filter(fn (SurveyField $f) => ! $f->is_hidden && ! $f->type->isContentBlock() && ! $f->isConditionallyVisible($safeVisible))
                ->pluck('field_key')
                ->all();

            $safeVisible = array_diff_key($safeVisible, array_flip($conditionallyHiddenKeys));
            $this->validateOptionCapacity($survey, $safeVisible);

            $finalAnswers = array_merge($safeVisible, $hiddenMap->values ?? []);
            $calculations = $this->calculateResponse->execute($survey, $safeVisible);

            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'response_number' => $this->generateResponseNumber($survey),
                'survey_recipient_id' => $recipient?->id,
                'survey_token_id' => $lockedToken?->id,
                'survey_collector_id' => $collector?->id,
                'submitted_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent,
                'calculations_json' => $calculations === [] ? null : $calculations,
                'completion_status' => SurveyResponseCompletionStatus::Complete,
                'is_test' => $recipient !== null && $recipient->is_test,
            ]);

            $fieldsByKey = $survey->fields->keyBy('field_key');
            $now = now();
            $answerRows = [];
            $fileUploads = [];

            foreach ($finalAnswers as $fieldKey => $value) {
                $field = $fieldsByKey->get($fieldKey);

                if (! $field instanceof SurveyField) {
                    continue;
                }

                $isArray = is_array($value);

                $answerRows[] = [
                    'survey_response_id' => $response->id,
                    'survey_field_id' => $field->id,
                    'answer_text' => $isArray ? null : ($value !== null ? (string) $value : null),
                    // insert() 繞過 cast，需手動編碼（與 Eloquent 'array' cast 預設一致）。
                    'answer_json' => $isArray ? json_encode($value) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($field->type->value === 'file_upload') {
                    $fileUploads[] = [$field, $value];
                }
            }

            // 單次批次寫入取代逐題 INSERT（30 題 = 1 query 而非 30 query）。
            if ($answerRows !== []) {
                SurveyAnswer::insert($answerRows);
            }

            foreach ($fileUploads as [$uploadField, $uploadValue]) {
                $this->attachUploadedFileToResponse($survey, $uploadField, $uploadValue, $response);
            }

            $minSeconds = $survey->settings()->anomalyMinSeconds;
            $surveyMinMs = $minSeconds !== null ? $minSeconds * 1000 : null;

            $this->evaluateQuality->execute($response->load('answers.field'), array_merge($qualityContext, [
                'ip' => $ip,
                'survey_min_ms' => $surveyMinMs,
            ]));

            // Record token usage
            $lockedToken?->recordUsage();

            SurveySubmitted::dispatch($response, $survey, $recipient);

            return $response->load('answers');
        });
    }

    private function lockValidToken(Survey $survey, ?SurveyToken $token): ?SurveyToken
    {
        if (! $token instanceof SurveyToken) {
            return null;
        }

        $lockedToken = SurveyToken::query()
            ->whereKey($token->id)
            ->lockForUpdate()
            ->first();

        if (
            ! $lockedToken instanceof SurveyToken
            || (int) $lockedToken->survey_id !== (int) $survey->id
            || ! $lockedToken->isActive()
            || $lockedToken->isExpired()
            || $lockedToken->isExhausted()
        ) {
            throw new SurveyNotAvailableException('連結無效或已過期。');
        }

        return $lockedToken;
    }

    private function generateResponseNumber(Survey $survey): ?string
    {
        if (empty($survey->settings_json['response_number'])) {
            return null;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $this->makeResponseNumber();

            if (! SurveyResponse::query()->where('response_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new SurveyNotAvailableException('無法產生填答編號，請稍後再試。');
    }

    private function makeResponseNumber(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random = '';

        for ($i = 0; $i < 6; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return 'SR-'.now()->format('Ymd').'-'.$random;
    }

    private function isMarketingActivityDuplicateSubmission(Survey $survey, ?int $tokenId, ?string $rawToken, ?int $recipientId): bool
    {
        if (! $tokenId || ! $rawToken || ! $recipientId) {
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
            ->where(function ($query) use ($tokenId, $rawToken): void {
                $query->where('survey_token_id', $tokenId)
                    ->orWhere('original_url', 'like', '%t='.$rawToken.'%');
            })
            ->exists();

        if (! $hasMarketingShortLink) {
            return false;
        }

        return SurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->where('survey_recipient_id', $recipientId)
            ->whereNotNull('submitted_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function validateOptionCapacity(Survey $survey, array $answers): void
    {
        $fieldsByKey = $survey->fields->keyBy('field_key');
        $errors = [];

        foreach ($answers as $fieldKey => $value) {
            $field = $fieldsByKey->get($fieldKey);

            if (! $field instanceof SurveyField || ! $field->type->requiresOptions()) {
                continue;
            }

            $selectedValues = is_array($value)
                ? array_map('strval', $value)
                : [(string) $value];

            // 只挑出「有容量上限且被選中」的選項；沒有就完全跳過鎖與 COUNT。
            $capacityOptions = array_filter(
                $field->normalizedOptions(),
                fn (array $option): bool => $option['capacity'] !== null
                    && $option['capacity'] >= 1
                    && in_array($option['value'], $selectedValues, true),
            );

            if ($capacityOptions === []) {
                continue;
            }

            // 序列化同一欄位的容量檢查，但不鎖 survey_fields 列（避免與後台編輯互卡）。
            // advisory lock 為 PostgreSQL 專屬；其他驅動（如測試用 sqlite）退回無鎖檢查。
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['field_capacity_'.$field->id]);
            }

            foreach ($capacityOptions as $option) {
                $usedCount = SurveyAnswer::query()
                    ->where('survey_field_id', $field->id)
                    ->where(function ($query) use ($option): void {
                        $query->where('answer_text', $option['value'])
                            ->orWhereJsonContains('answer_json', $option['value']);
                    })
                    ->count();

                if ($usedCount >= $option['capacity']) {
                    $errors[$field->field_key][] = "{$option['label']} 已額滿。";
                }
            }
        }

        if ($errors !== []) {
            throw new SurveyValidationException($errors);
        }
    }

    private function attachUploadedFileToResponse(Survey $survey, SurveyField $field, mixed $value, SurveyResponse $response): void
    {
        if ($field->type->value !== 'file_upload' || ! is_array($value) || empty($value['media_id'])) {
            return;
        }

        $media = $this->uploadToken->resolve($value, $survey, $field);

        if (! $media instanceof Media) {
            return;
        }

        $previousOwnerType = $media->model_type;
        $previousOwnerId = $media->model_id;

        $media->model_type = $response->getMorphClass();
        $media->model_id = $response->getKey();
        $media->save();

        $this->deleteEmptiedDraftResponse($previousOwnerType, $previousOwnerId, $response);
    }

    /**
     * 媒體搬到正式回覆後，刪除已被搬空的 Partial 暫存草稿（避免孤兒列堆積）。
     */
    private function deleteEmptiedDraftResponse(?string $previousOwnerType, int|string|null $previousOwnerId, SurveyResponse $response): void
    {
        if ($previousOwnerId === null || (int) $previousOwnerId === (int) $response->getKey()) {
            return;
        }

        if ($previousOwnerType !== $response->getMorphClass()) {
            return;
        }

        $draft = SurveyResponse::query()->find($previousOwnerId);

        if (! $draft instanceof SurveyResponse) {
            return;
        }

        $isEmptiedDraft = $draft->completion_status === SurveyResponseCompletionStatus::Partial
            && $draft->submitted_at === null
            && $draft->getMedia('survey_files')->isEmpty();

        if ($isEmptiedDraft) {
            $draft->delete();
        }
    }
}
