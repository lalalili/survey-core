<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyTokenStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => '啟用',
            self::Inactive => '停用',
        };
    }
}
