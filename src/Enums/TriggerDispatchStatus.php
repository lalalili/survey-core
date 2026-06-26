<?php

namespace Lalalili\SurveyCore\Enums;

enum TriggerDispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '等待中',
            self::Sent => '已送出',
            self::Failed => '失敗',
            self::Skipped => '略過',
        };
    }
}
