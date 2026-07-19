<?php

namespace Lalalili\SurveyCore\Support;

final class SurveyResultContextFields
{
    /**
     * @var array<string, array{field_key: string, label: string}>
     */
    public const DEFINITIONS = [
        'dealer' => [
            'field_key' => 'system_context_dealer',
            'label' => '經銷商',
        ],
        'location' => [
            'field_key' => 'system_context_location',
            'label' => '據點',
        ],
        'vehicle_plate' => [
            'field_key' => 'system_context_vehicle_plate',
            'label' => '車牌',
        ],
        'delivery_date' => [
            'field_key' => 'system_context_delivery_date',
            'label' => '交車日',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        return array_column(self::DEFINITIONS, 'field_key');
    }
}
