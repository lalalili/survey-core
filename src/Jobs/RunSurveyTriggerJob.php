<?php

namespace Lalalili\SurveyCore\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Lalalili\SurveyCore\Actions\EvaluateAnswerRuleTreeAction;
use Lalalili\SurveyCore\Actions\Triggers\BuildDmsRequestParameters;
use Lalalili\SurveyCore\Actions\Triggers\DispatchDmsSoapTriggerAction;
use Lalalili\SurveyCore\Actions\Triggers\DispatchHttpTriggerAction;
use Lalalili\SurveyCore\Actions\Triggers\ExpandPresetsAction;
use Lalalili\SurveyCore\Actions\Triggers\ResolveActionPayloadAction;
use Lalalili\SurveyCore\Enums\DmsExecutionMode;
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
    ) {}

    public function handle(
        EvaluateAnswerRuleTreeAction $evaluator,
        ResolveActionPayloadAction $payloadResolver,
        DispatchHttpTriggerAction $httpDispatcher,
        BuildDmsRequestParameters $dmsParameters,
        DispatchDmsSoapTriggerAction $dmsDispatcher,
    ): void {
        $rule = SurveyTriggerRule::findOrFail($this->triggerRuleId);
        $response = SurveyResponse::with(['answers.field', 'recipient', 'token'])->findOrFail($this->surveyResponseId);

        $matched = $evaluator->execute($response, $rule->rule_tree_json);

        if (! $matched) {
            return;
        }

        $answerMap = $response->answerMapByFieldKey();

        // 將 preset 參照展開為具體 http_post 動作（向下相容舊有 inline 動作）。
        $actions = app(ExpandPresetsAction::class)->execute($rule->actions_json ?? []);

        foreach ($actions as $action) {
            $actionType = (string) ($action['type'] ?? '');

            if (! in_array($actionType, ['http_post', 'dms_soap'], true)) {
                continue;
            }

            $actionKey = $this->actionKey($action);

            // 守衛：限「有 token（邀請連結）且未逾期」的填答才觸發（發點券用）。
            // 預設 false，不影響顧管立案等對匿名填答也要觸發的動作。
            if (($action['require_valid_token'] ?? false)
                && ($response->survey_token_id === null || $response->token?->isExpired())) {
                Log::info('survey-trigger skipped: require_valid_token not satisfied', [
                    'rule_id' => $rule->id,
                    'response_id' => $response->id,
                    'action' => $action['name'] ?? null,
                ]);

                continue;
            }

            $dispatch = SurveyTriggerDispatch::firstOrCreate(
                [
                    'survey_trigger_rule_id' => $rule->id,
                    'survey_response_id' => $response->id,
                    'action_key' => $actionKey,
                ],
                ['status' => TriggerDispatchStatus::Pending],
            );

            if ($dispatch->status === TriggerDispatchStatus::Sent) {
                continue;
            }

            if ($actionType === 'dms_soap') {
                if (! (bool) config('external-communications.enabled', true)) {
                    $dmsDispatcher->execute(
                        action: $action,
                        parameters: [],
                        mode: DmsExecutionMode::Automatic,
                        actionKey: $actionKey,
                        dispatch: $dispatch,
                        presetId: isset($action['_preset_id']) ? (int) $action['_preset_id'] : null,
                    );

                    continue;
                }

                $parameters = $dmsParameters->fromResponse($response, $dispatch, $action, $actionKey);
                $dmsDispatcher->execute(
                    action: $action,
                    parameters: $parameters,
                    mode: DmsExecutionMode::Automatic,
                    actionKey: $actionKey,
                    dispatch: $dispatch,
                    presetId: isset($action['_preset_id']) ? (int) $action['_preset_id'] : null,
                );

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
            'rule_id' => $rule->id,
            'response_id' => $response->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function actionKey(array $action): string
    {
        if (filled($action['_action_key'] ?? null)) {
            return (string) $action['_action_key'];
        }

        return 'inline:'.hash('sha256', json_encode($action, JSON_THROW_ON_ERROR));
    }
}
