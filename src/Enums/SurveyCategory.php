<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyCategory: string
{
    case Ssi = 'SSI';
    case Csi = 'CSI';
    case Iqs = 'IQS';

    public function label(): string
    {
        return match ($this) {
            self::Ssi => 'SSI 銷售滿意度',
            self::Csi => 'CSI 服務滿意度',
            self::Iqs => 'IQS 新車品質',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])
            ->all();
    }
}
