<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyResponseCompletionStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Complete => '完整',
            self::Partial => '部分',
        };
    }
}
