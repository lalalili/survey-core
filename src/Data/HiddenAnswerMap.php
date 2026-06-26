<?php

namespace Lalalili\SurveyCore\Data;

final readonly class HiddenAnswerMap
{
    /** @param  array<string, mixed>  $values  Keyed by field_key */
    public function __construct(public array $values) {}

    public function get(string $fieldKey): mixed
    {
        return $this->values[$fieldKey] ?? null;
    }

    public function has(string $fieldKey): bool
    {
        return array_key_exists($fieldKey, $this->values);
    }
}
