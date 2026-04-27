<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

class SurveyScheduleCommand extends Command
{
    protected $signature = 'survey:schedule';

    protected $description = 'Publish and close surveys according to their configured schedule.';

    public function handle(): int
    {
        $now = now();

        $published = Survey::query()
            ->where('status', SurveyStatus::Draft->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->update([
                'status' => SurveyStatus::Published->value,
                'published_at' => $now,
            ]);

        $closed = Survey::query()
            ->where('status', SurveyStatus::Published->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->update(['status' => SurveyStatus::Closed->value]);

        $this->components->info("Published {$published} survey(s), closed {$closed} survey(s).");

        return self::SUCCESS;
    }
}
