<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;

class PruneSurveyTriggerActionAttemptsCommand extends Command
{
    protected $signature = 'survey:prune-trigger-action-attempts {--days=90 : 保留天數}';

    protected $description = '清除超過保留天數的問卷觸發動作請求與回覆紀錄。';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        SurveyTriggerActionAttempt::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($attempts) use (&$deleted): void {
                $deleted += SurveyTriggerActionAttempt::query()
                    ->whereKey($attempts->modelKeys())
                    ->delete();
            });

        $this->components->info("Pruned {$deleted} trigger action attempt(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
