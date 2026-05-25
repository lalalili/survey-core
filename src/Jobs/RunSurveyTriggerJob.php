<?php

namespace Lalalili\SurveyCore\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Lalalili\SurveyCore\Actions\EvaluateAnswerRuleTreeAction;
use Lalalili\SurveyCore\Actions\Triggers\DispatchHttpTriggerAction;
use Lalalili\SurveyCore\Actions\Triggers\ResolveActionPayloadAction;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

class RunSurveyTriggerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $triggerRuleId,
        public readonly int $surveyResponseId,
    ) {
    }

    public function handle(
        EvaluateAnswerRuleTreeAction $evaluator,
        ResolveActionPayloadAction $payloadResolver,
        DispatchHttpTriggerAction $httpDispatcher,
    ): void {
        $rule = SurveyTriggerRule::findOrFail($this->triggerRuleId);
        $response = SurveyResponse::with('answers.field')->findOrFail($this->surveyResponseId);

        $matched = $evaluator->execute($response, $rule->rule_tree_json);

        if (! $matched) {
            return;
        }

        $answerMap = $response->answers
            ->mapWithKeys(fn ($a) => [$a->field->field_key => $a->getValue()])
            ->all();

        foreach ($rule->actions_json as $action) {
            if (($action['type'] ?? '') !== 'http_post') {
                continue;
            }

            $dispatch = SurveyTriggerDispatch::firstOrCreate(
                [
                    'survey_trigger_rule_id' => $rule->id,
                    'survey_response_id'     => $response->id,
                ],
                ['status' => TriggerDispatchStatus::Pending],
            );

            if ($dispatch->status === TriggerDispatchStatus::Sent) {
                continue;
            }

            $template = $action['payload_template'] ?? [];
            if (is_string($template)) {
                $template = json_decode($template, true) ?? [];
            }

            $resolvedPayload = $payloadResolver->execute($template, $response, $answerMap);

            $httpDispatcher->execute($dispatch, $action, $resolvedPayload);
        }

        $rule->increment('triggered_count');
        $rule->last_triggered_at = now();
        $rule->saveQuietly();

        Log::info('survey-trigger fired', [
            'rule_id'     => $rule->id,
            'response_id' => $response->id,
        ]);
    }
}
