<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

class SubmitSurveyResponseAction
{
    public function __construct(
        private readonly ResolveSurveyTokenAction $resolveToken,
        private readonly HydratePersonalizedFieldsAction $hydrateFields,
        private readonly ValidateSurveySubmissionAction $validateSubmission,
    ) {
    }

    public function execute(Survey $survey, SubmissionPayload $payload, string $ip = '', string $userAgent = ''): SurveyResponse
    {
        // Validate against visible fields only
        $this->validateSubmission->execute($survey, $payload->visibleAnswers, $payload->tokenContext);

        return DB::transaction(function () use ($survey, $payload, $ip, $userAgent) {
            $tokenContext = $payload->tokenContext;
            $recipient = $tokenContext?->recipient;

            // Hydrate server-side personalized hidden values
            $hiddenMap = $recipient
                ? $this->hydrateFields->execute($survey->fields, $recipient)
                : null;

            // Build final answer map:
            // 1. Start with visible answers
            // 2. Strip any hidden field keys the frontend may have smuggled in
            // 3. Merge server-resolved hidden values
            // Keys for is_hidden fields + conditionally hidden (branching) fields
            $hiddenKeys = $survey->fields
                ->filter(fn (SurveyField $f) => $f->is_hidden)
                ->pluck('field_key')
                ->all();

            $safeVisible = array_diff_key($payload->visibleAnswers, array_flip($hiddenKeys));

            // Strip answers for fields whose branching condition is not met
            $conditionallyHiddenKeys = $survey->fields
                ->filter(fn (SurveyField $f) => ! $f->is_hidden && ! $f->isConditionallyVisible($safeVisible))
                ->pluck('field_key')
                ->all();

            $safeVisible = array_diff_key($safeVisible, array_flip($conditionallyHiddenKeys));
            $finalAnswers = array_merge($safeVisible, $hiddenMap?->values ?? []);

            $response = SurveyResponse::create([
                'survey_id'           => $survey->id,
                'survey_recipient_id' => $recipient?->id,
                'survey_token_id'     => $tokenContext?->token->id,
                'submitted_at'        => now(),
                'ip'                  => $ip,
                'user_agent'          => $userAgent,
                'completion_status'   => SurveyResponseCompletionStatus::Complete,
            ]);

            $fieldsByKey = $survey->fields->keyBy('field_key');

            foreach ($finalAnswers as $fieldKey => $value) {
                $field = $fieldsByKey->get($fieldKey);

                if (! $field instanceof SurveyField) {
                    continue;
                }

                $answerData = [
                    'survey_response_id' => $response->id,
                    'survey_field_id'    => $field->id,
                ];

                if (is_array($value)) {
                    $answerData['answer_json'] = $value;
                } else {
                    $answerData['answer_text'] = $value !== null ? (string) $value : null;
                }

                SurveyAnswer::create($answerData);
            }

            // Record token usage
            $tokenContext?->token->recordUsage();

            SurveySubmitted::dispatch($response, $survey, $recipient);

            return $response->load('answers');
        });
    }
}
