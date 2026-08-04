<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Illuminate\Database\Eloquent\Builder;
use Lalalili\SurveyCore\Actions\EvaluateAnswerRuleTreeAction;
use Lalalili\SurveyCore\Enums\TriggerRunStatus;
use Lalalili\SurveyCore\Enums\TriggerRunType;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;
use Lalalili\SurveyCore\Models\SurveyTriggerRuleRun;
use Throwable;

/**
 * 批次／手動執行單一觸發規則：掃描候選填答，逐筆評估規則樹，符合者派送，並留下執行紀錄。
 *
 * 派送沿用 {@see RunSurveyTriggerJob}（單筆冪等：已送出則不重複），規則的 triggered_count／
 * last_triggered_at 亦由該 Job 維護，本 Action 不重複累加。
 */
class RunTriggerRuleBatchAction
{
    public function __construct(
        private readonly EvaluateAnswerRuleTreeAction $evaluator,
    ) {
    }

    /**
     * @param  int|null  $responseId  指定 → 手動單筆（限該填答）；null → 排程掃描近 N 天未派送的填答。
     */
    public function execute(
        SurveyTriggerRule $rule,
        TriggerRunType $type,
        ?int $responseId = null,
    ): SurveyTriggerRuleRun {
        $run = SurveyTriggerRuleRun::create([
            'survey_trigger_rule_id' => $rule->id,
            'trigger_type' => $type,
            'status' => TriggerRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $candidates = $this->candidatesQuery($rule, $responseId);

            $scanned = 0;
            $matched = 0;
            $dispatched = 0;

            foreach ($this->responses($candidates, $responseId) as $response) {
                $scanned++;

                if (! $this->evaluator->execute($response, $rule->rule_tree_json ?? [])) {
                    continue;
                }

                $matched++;
                RunSurveyTriggerJob::dispatch($rule->id, $response->id);
                $dispatched++;
            }

            $run->update([
                'status' => TriggerRunStatus::Completed,
                'scanned_count' => $scanned,
                'matched_count' => $matched,
                'dispatched_count' => $dispatched,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => TriggerRunStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $run->refresh();
    }

    /**
     * @return Builder<SurveyResponse>
     */
    private function candidatesQuery(SurveyTriggerRule $rule, ?int $responseId): Builder
    {
        $query = SurveyResponse::query()
            ->with(['answers.field', 'recipient', 'token'])
            ->where('survey_id', $rule->survey_id)
            ->where('is_test', false)
            ->whereNotNull('submitted_at');

        if ($responseId !== null) {
            $query->whereKey($responseId);

            return $query;
        }

        // 排程掃描：近 N 天提交、且此規則尚未派送過的填答。
        $query
            ->where('submitted_at', '>=', now()->subDays($rule->schedule_window_days))
            ->whereNotExists(function ($sub) use ($rule): void {
                $sub->selectRaw('1')
                    ->from('survey_trigger_dispatches')
                    ->whereColumn('survey_trigger_dispatches.survey_response_id', 'survey_responses.id')
                    ->where('survey_trigger_dispatches.survey_trigger_rule_id', $rule->id);
            });

        return $query;
    }

    /**
     * @param  Builder<SurveyResponse>  $query
     * @return iterable<int, SurveyResponse>
     */
    private function responses(Builder $query, ?int $responseId): iterable
    {
        if ($responseId !== null) {
            return $query->get();
        }

        return $query->lazyById();
    }
}
