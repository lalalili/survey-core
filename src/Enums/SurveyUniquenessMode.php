<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyUniquenessMode: string
{
    case None = 'none';
    case Email = 'email';
    case Token = 'token';
    case Ip = 'ip';
    case Cookie = 'cookie';

    public function label(): string
    {
        return match ($this) {
            self::None => '不限制',
            self::Email => 'Email',
            self::Token => 'Token',
            self::Ip => 'IP',
            self::Cookie => 'Cookie',
        };
    }
}
