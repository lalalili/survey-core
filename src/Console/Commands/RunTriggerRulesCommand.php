<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Actions\Triggers\RunTriggerRuleBatchAction;
use Lalalili\SurveyCore\Enums\TriggerRunType;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;
use Throwable;

class RunTriggerRulesCommand extends Command
{
    protected $signature = 'survey:run-trigger-rules';

    protected $description = 'Run scheduled survey trigger rules whose daily schedule time is due.';

    public function handle(RunTriggerRuleBatchAction $batch): int
    {
        if (! $this->scheduleColumnsAreReady()) {
            $this->components->warn('Survey trigger schedule columns are not migrated yet; skipping this run.');

            return self::SUCCESS;
        }

        $now = now();

        $rules = SurveyTriggerRule::query()
            ->where('is_active', true)
            ->where('schedule_enabled', true)
            ->where('schedule_time', $now->format('H:i'))
            ->where(function ($query) use ($now): void {
                $query->whereNull('last_scheduled_run_at')
                    ->orWhereDate('last_scheduled_run_at', '!=', $now->toDateString());
            })
            ->get();

        foreach ($rules as $rule) {
            try {
                $run = $batch->execute($rule, TriggerRunType::Scheduled);

                $rule->last_scheduled_run_at = now();
                $rule->saveQuietly();

                $this->components->info(sprintf(
                    '規則 #%d「%s」：掃描 %d／符合 %d／派送 %d',
                    $rule->id,
                    $rule->name,
                    $run->scanned_count,
                    $run->matched_count,
                    $run->dispatched_count,
                ));
            } catch (Throwable $e) {
                $this->components->error(sprintf('規則 #%d「%s」執行失敗：%s', $rule->id, $rule->name, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function scheduleColumnsAreReady(): bool
    {
        $table = (string) config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules');

        return Schema::hasColumn($table, 'schedule_enabled')
            && Schema::hasColumn($table, 'schedule_time')
            && Schema::hasColumn($table, 'last_scheduled_run_at');
    }
}
