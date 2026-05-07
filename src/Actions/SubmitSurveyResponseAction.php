<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
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
use Lalalili\SurveyCore\Support\JumpLogicResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SubmitSurveyResponseAction
{
    public function __construct(
        private readonly HydratePersonalizedFieldsAction $hydrateFields,
        private readonly ValidateSurveySubmissionAction $validateSubmission,
        private readonly CalculateSurveyResponseAction $calculateResponse,
        private readonly EvaluateResponseQualityAction $evaluateQuality,
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
    ): SurveyResponse
    {
        // Validate against visible fields only
        $this->validateSubmission->execute($survey, $payload->visibleAnswers, $payload->tokenContext);

        return DB::transaction(function () use ($survey, $payload, $ip, $userAgent, $qualityContext, $collector) {
            $lockedSurvey = Survey::query()->lockForUpdate()->findOrFail($survey->id);

            if (! $lockedSurvey->hasQuotaAvailable()) {
                throw new SurveyNotAvailableException($lockedSurvey->quota_message ?: '問卷已額滿。');
            }

            $tokenContext = $payload->tokenContext;
            $recipient = $tokenContext?->recipient;

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
                'survey_recipient_id' => $recipient?->id,
                'survey_token_id' => $tokenContext?->token->id,
                'survey_collector_id' => $collector?->id,
                'submitted_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent,
                'calculations_json' => $calculations === [] ? null : $calculations,
                'completion_status' => SurveyResponseCompletionStatus::Complete,
            ]);

            $fieldsByKey = $survey->fields->keyBy('field_key');

            foreach ($finalAnswers as $fieldKey => $value) {
                $field = $fieldsByKey->get($fieldKey);

                if (! $field instanceof SurveyField) {
                    continue;
                }

                $answerData = [
                    'survey_response_id' => $response->id,
                    'survey_field_id' => $field->id,
                ];

                if (is_array($value)) {
                    $answerData['answer_json'] = $value;
                } else {
                    $answerData['answer_text'] = $value !== null ? (string) $value : null;
                }

                SurveyAnswer::create($answerData);
                $this->attachUploadedFileToResponse($field, $value, $response);
            }

            $this->evaluateQuality->execute($response->load('answers.field'), array_merge($qualityContext, ['ip' => $ip]));

            // Record token usage
            $tokenContext?->token->recordUsage();

            SurveySubmitted::dispatch($response, $survey, $recipient);

            return $response->load('answers');
        });
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

            SurveyField::query()->whereKey($field->id)->lockForUpdate()->first();

            $selectedValues = is_array($value)
                ? array_map('strval', $value)
                : [(string) $value];

            foreach ($field->normalizedOptions() as $option) {
                if ($option['capacity'] === null || $option['capacity'] < 1) {
                    continue;
                }

                if (! in_array($option['value'], $selectedValues, true)) {
                    continue;
                }

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

    private function attachUploadedFileToResponse(SurveyField $field, mixed $value, SurveyResponse $response): void
    {
        if ($field->type->value !== 'file_upload' || ! is_array($value) || empty($value['media_id'])) {
            return;
        }

        $media = Media::query()
            ->whereKey((int) $value['media_id'])
            ->where('collection_name', 'survey_files')
            ->first();

        if (! $media instanceof Media) {
            return;
        }

        $media->model_type = $response->getMorphClass();
        $media->model_id = $response->getKey();
        $media->save();
    }
}
