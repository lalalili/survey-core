<?php

namespace Lalalili\SurveyCore\Support;

final class ConditionGroupEvaluator
{
    private const MAX_DEPTH = 10;

    /**
     * Evaluate a condition group. Each entry under `conditions` is either a leaf
     * condition (`{field_key, op, value}`) or a nested group (`{logic, conditions}`),
     * allowing arbitrarily nested AND/OR trees. `$depth` guards malformed input.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $answers
     */
    public static function passes(array $group, array $answers, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        $rawConditions = is_array($group['conditions'] ?? null) ? $group['conditions'] : [];
        $conditions = collect($rawConditions)
            ->filter(fn (mixed $condition): bool => is_array($condition))
            ->values();

        if ($conditions->isEmpty()) {
            return true;
        }

        $logic = strtolower((string) ($group['logic'] ?? 'and'));

        $evaluate = fn (array $condition): bool => self::isGroup($condition)
            ? self::passes($condition, $answers, $depth + 1)
            : self::conditionPasses($condition, $answers);

        if ($logic === 'or') {
            return $conditions->contains($evaluate);
        }

        return $conditions->every($evaluate);
    }

    /**
     * A node is a nested group when it carries its own `conditions` array.
     *
     * @param  array<string, mixed>  $node
     */
    private static function isGroup(array $node): bool
    {
        return array_key_exists('conditions', $node) && is_array($node['conditions']);
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

        if ($op === 'is_empty') {
            return blank($current);
        }

        if ($op === 'is_not_empty') {
            return filled($current);
        }

        // 目標題目未作答時，除了 is_empty / is_not_empty 之外一律不成立。
        // 否則否定運算子（not_equals、not_contains）會在使用者還沒作答時就成立，
        // 讓被條件控制的題目或頁面跳轉提前觸發。注意 blank() 不會把 0 / '0'
        // 視為空值，評分 0 分仍是有效答案。
        if (blank($current)) {
            return false;
        }

        return match ($op) {
            'not_equals' => ! self::equals($current, $expected),
            'contains' => self::contains($current, $expected),
            'not_contains' => ! self::contains($current, $expected),
            'greater_than', '>' => is_numeric($current) && is_numeric($expected) && (float) $current > (float) $expected,
            'greater_than_or_equal', '>=' => is_numeric($current) && is_numeric($expected) && (float) $current >= (float) $expected,
            'less_than', '<' => is_numeric($current) && is_numeric($expected) && (float) $current < (float) $expected,
            'less_than_or_equal', '<=' => is_numeric($current) && is_numeric($expected) && (float) $current <= (float) $expected,
            'between' => self::between($current, $expected),
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
