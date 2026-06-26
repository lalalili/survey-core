<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Models\Survey;

class CheckTurnstileConfigCommand extends Command
{
    protected $signature = 'survey:check-turnstile';

    protected $description = '檢查是否有問卷啟用 Turnstile 人機驗證卻未在伺服器設定金鑰（會導致該問卷所有送出被擋）。';

    public function handle(): int
    {
        $secret = config('survey-core.turnstile.secret_key');
        $secretConfigured = is_string($secret) && $secret !== '';

        $enabled = Survey::query()
            ->where('settings_json->anomaly->turnstile', true)
            ->get(['id', 'title', 'status']);

        if ($secretConfigured) {
            $this->components->info("Turnstile secret 已設定；{$enabled->count()} 份問卷啟用人機驗證。");

            return self::SUCCESS;
        }

        if ($enabled->isEmpty()) {
            $this->components->info('Turnstile secret 未設定，但目前沒有問卷啟用人機驗證。');

            return self::SUCCESS;
        }

        $this->components->error('Turnstile secret 未設定，但下列問卷已啟用人機驗證，這些問卷的所有送出都會被擋：');

        $this->table(
            ['ID', '標題', '狀態'],
            $enabled->map(fn (Survey $survey) => [$survey->id, $survey->title, $survey->status->value])->all()
        );

        $this->components->warn('請設定 survey-core.turnstile.secret_key（TURNSTILE_SECRET_KEY），或關閉這些問卷的人機驗證。');

        return self::FAILURE;
    }
}
