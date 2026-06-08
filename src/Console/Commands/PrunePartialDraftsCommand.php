<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Models\SurveyResponse;

class PrunePartialDraftsCommand extends Command
{
    protected $signature = 'survey:prune-partial-drafts {--hours= : 保留時數，預設取 survey-core.uploads.partial_draft_retention_hours}';

    protected $description = '清除被放棄的 Partial 暫存草稿回覆（含其媒體），這些草稿由檔案上傳建立但未完成送出。';

    public function handle(): int
    {
        $hours = $this->option('hours') !== null
            ? max(0, (int) $this->option('hours'))
            : (int) config('survey-core.uploads.partial_draft_retention_hours', 24);

        $cutoff = now()->subHours($hours);

        $drafts = SurveyResponse::query()
            ->where('completion_status', SurveyResponseCompletionStatus::Partial->value)
            ->whereNull('submitted_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $deleted = 0;

        foreach ($drafts as $draft) {
            // delete() 會一併清掉關聯媒體（Spatie HasMedia 透過 model 事件）。
            $draft->delete();
            $deleted++;
        }

        $this->components->info("Pruned {$deleted} abandoned partial draft(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
