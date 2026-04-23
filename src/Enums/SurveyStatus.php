<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => '草稿',
            self::Published => '已發佈',
            self::Closed    => '已關閉',
            self::Archived  => '已封存',
        };
    }

    public function isAcceptingSubmissions(): bool
    {
        return $this === self::Published;
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
