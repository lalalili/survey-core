<?php

namespace Lalalili\SurveyCore\Support;

final class ConditionGroupEvaluator
{
    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $answers
     */
    public static function passes(array $group, array $answers): bool
    {
        $conditions = collect($group['conditions'] ?? [])
            ->filter(fn (mixed $condition): bool => is_array($condition))
            ->values();

        if ($conditions->isEmpty()) {
            return true;
        }

        $logic = strtolower((string) ($group['logic'] ?? 'and'));

        if ($logic === 'or') {
            return $conditions->contains(fn (array $condition): bool => self::conditionPasses($condition, $answers));
        }

        return $conditions->every(fn (array $condition): bool => self::conditionPasses($condition, $answers));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $answers
     */
    private static function conditionPasses(array $condition, array $answers): bool
    {
        $fieldKey = (string) ($condition['field_key'] ?? '');
        $op = (string) ($condition['op'] ?? 'equals');
        $expected = $condition['value'] ?? null;
        $current = $answers[$fieldKey] ?? null;

        return match ($op) {
            'not_equals' => ! self::equals($current, $expected),
            'contains' => self::contains($current, $expected),
            'not_contains' => ! self::contains($current, $expected),
            'greater_than', '>' => is_numeric($current) && is_numeric($expected) && (float) $current > (float) $expected,
            'greater_than_or_equal', '>=' => is_numeric($current) && is_numeric($expected) && (float) $current >= (float) $expected,
            'less_than', '<' => is_numeric($current) && is_numeric($expected) && (float) $current < (float) $expected,
            'less_than_or_equal', '<=' => is_numeric($current) && is_numeric($expected) && (float) $current <= (float) $expected,
            'between' => self::between($current, $expected),
            'is_empty' => blank($current),
            'is_not_empty' => filled($current),
            default => self::equals($current, $expected),
        };
    }

    private static function equals(mixed $current, mixed $expected): bool
    {
        if (is_array($current)) {
            return in_array((string) $expected, array_map('strval', $current), true);
        }

        return (string) $current === (string) $expected;
    }

    private static function contains(mixed $current, mixed $expected): bool
    {
        if (is_array($current)) {
            return in_array((string) $expected, array_map('strval', $current), true);
        }

        return str_contains((string) $current, (string) $expected);
    }

    private static function between(mixed $current, mixed $expected): bool
    {
        if (! is_numeric($current) || ! is_array($expected)) {
            return false;
        }

        $min = $expected['min'] ?? $expected[0] ?? null;
        $max = $expected['max'] ?? $expected[1] ?? null;

        if (! is_numeric($min) || ! is_numeric($max)) {
            return false;
        }

        $value = (float) $current;

        return $value >= (float) $min && $value <= (float) $max;
    }
}
