<?php

namespace Lalalili\SurveyCore\Enums;

enum TriggerRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => '執行中',
            self::Completed => '完成',
            self::Failed => '失敗',
        };
    }
}
