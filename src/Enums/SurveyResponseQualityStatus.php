<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyResponseQualityStatus: string
{
    case Accepted = 'accepted';
    case Flagged = 'flagged';
    case Quarantined = 'quarantined';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => '已接受',
            self::Flagged => '待檢查',
            self::Quarantined => '已隔離',
        };
    }
}
