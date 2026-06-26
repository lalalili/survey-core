<?php

namespace Lalalili\SurveyCore\Enums;

enum TriggerRunType: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => '排程',
            self::Manual => '手動',
        };
    }
}
