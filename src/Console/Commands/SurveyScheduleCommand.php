<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Throwable;

class SurveyScheduleCommand extends Command
{
    protected $signature = 'survey:schedule';

    protected $description = 'Publish and close surveys according to their configured schedule.';

    /**
     * 排程發佈必須走 PublishSurveyAction：直接改 status 只會讓問卷「看起來」上架，
     * 但 draft_schema 不會同步到 survey_fields / settings_json，也不會建立
     * published_schema_version，公開頁會渲染出一份沒有題目的空白問卷。
     */
    public function handle(PublishSurveyAction $publishSurvey): int
    {
        $now = now();
        $published = 0;
        $failed = 0;

        Survey::query()
            ->where('status', SurveyStatus::Draft->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function (Collection $surveys) use ($publishSurvey, &$published, &$failed): void {
                foreach ($surveys as $survey) {
                    try {
                        $publishSurvey->execute($survey);
                        $published++;
                    } catch (Throwable $exception) {
                        // 單一問卷發佈失敗（例如含檔案上傳題但未綁定 Google Drive）
                        // 不應阻擋其餘到期問卷。
                        $failed++;
                        report($exception);
                        $this->components->error(
                            "Survey #{$survey->id} could not be published: {$exception->getMessage()}",
                        );
                    }
                }
            });

        $closed = Survey::query()
            ->where('status', SurveyStatus::Published->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->update(['status' => SurveyStatus::Closed->value]);

        $this->components->info("Published {$published} survey(s), closed {$closed} survey(s).");

        if ($failed > 0) {
            $this->components->warn("{$failed} survey(s) failed to publish; see the logs for details.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
