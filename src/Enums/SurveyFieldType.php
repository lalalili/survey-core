<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Select = 'select';
    case Rating = 'rating';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::ShortText      => '單行文字',
            self::LongText       => '多行文字',
            self::SingleChoice   => '單選',
            self::MultipleChoice => '多選',
            self::Select         => '下拉選單',
            self::Rating         => '評分',
            self::Email          => 'Email',
            self::Phone          => '電話',
            self::Date           => '日期',
            self::Hidden         => '隱藏欄位',
        };
    }

    public function requiresOptions(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice, self::Select]);
    }

    public function isAlwaysHidden(): bool
    {
        return $this === self::Hidden;
    }

    public function supportsMultipleValues(): bool
    {
        return $this === self::MultipleChoice;
    }
}
