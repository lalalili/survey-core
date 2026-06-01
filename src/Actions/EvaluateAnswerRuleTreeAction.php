<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Models\SurveyResponse;

class EvaluateAnswerRuleTreeAction
{
    /**
     * Meta pseudo-field：回填距邀請天數（token 產生時間 → 提交時間的天數）。
     * 供觸發規則設定「回填距邀請 ≤ X 天」之類條件；非問卷答案欄位。
     */
    public const META_DAYS_SINCE_INVITATION = 'days_since_invitation';

    /**
     * Evaluate a rule_tree_json against the answers of a SurveyResponse.
     *
     * 同時相容兩種規則樹格式：
     *   - 觸發評估格式：group { "logic", "rules" }
     *   - 規則樹編輯器（RuleTreeField / builder-ui-core）格式：group { "op", "children" }
     * leaf 皆為 { "field", "operator", "value" }。
     *
     * Supported operators: =, !=, >, >=, <, <=, in, not_in, contains,
     *   not_contains, starts_with, ends_with,
     *   is_empty/is_null（為空）, is_not_empty/is_not_null（不為空）
     *
     * @param  array<string, mixed>  $ruleTree
     */
    public function execute(SurveyResponse $response, array $ruleTree): bool
    {
        $response->loadMissing('answers.field', 'token');

        $answerMap = $response->answers
            ->mapWithKeys(fn ($a) => [$a->field->field_key => $a->getValue()])
            ->all();

        // 注入 meta：回填距邀請天數。無 token／未提交者給極大值，使「≤ X 天」不成立。
        $token = $response->token;
        $answerMap[self::META_DAYS_SINCE_INVITATION] = ($token !== null && $token->created_at !== null && $response->submitted_at !== null)
            ? $token->created_at->diffInDays($response->submitted_at)
            : PHP_INT_MAX;

        return $this->evaluateNode($ruleTree, $answerMap);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $answerMap
     */
    private function evaluateNode(array $node, array $answerMap): bool
    {
        // group：兩種格式擇一存在即視為群組（logic/rules 或 op/children）。
        if (isset($node['logic']) || isset($node['rules']) || isset($node['op']) || isset($node['children'])) {
            return $this->evaluateGroup($node, $answerMap);
        }

        return $this->evaluateLeaf($node, $answerMap);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $answerMap
     */
    private function evaluateGroup(array $group, array $answerMap): bool
    {
        $logic = strtoupper((string) ($group['logic'] ?? $group['op'] ?? 'AND'));
        $rules = $group['rules'] ?? $group['children'] ?? [];

        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            $result = $this->evaluateNode($rule, $answerMap);

            if ($logic === 'AND' && ! $result) {
                return false;
            }

            if ($logic === 'OR' && $result) {
                return true;
            }
        }

        return $logic === 'AND';
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, mixed>  $answerMap
     */
    private function evaluateLeaf(array $leaf, array $answerMap): bool
    {
        $fieldKey = $leaf['field'] ?? '';
        $operator = $leaf['operator'] ?? '=';
        $ruleValue = $leaf['value'] ?? null;

        $actual = $answerMap[$fieldKey] ?? null;

        return match ($operator) {
            '=' => $this->castScalar($actual) == $this->castScalar($ruleValue),
            '!=' => $this->castScalar($actual) != $this->castScalar($ruleValue),
            '>' => (float) $actual > (float) $ruleValue,
            '>=' => (float) $actual >= (float) $ruleValue,
            '<' => (float) $actual < (float) $ruleValue,
            '<=' => (float) $actual <= (float) $ruleValue,
            'in' => $this->inOperator($actual, $ruleValue),
            'not_in' => ! $this->inOperator($actual, $ruleValue),
            'contains' => str_contains((string) $actual, (string) $ruleValue),
            'not_contains' => ! str_contains((string) $actual, (string) $ruleValue),
            'starts_with' => str_starts_with((string) $actual, (string) $ruleValue),
            'ends_with' => str_ends_with((string) $actual, (string) $ruleValue),
            'is_empty', 'is_null' => $this->isEmpty($actual),
            'is_not_empty', 'is_not_null' => ! $this->isEmpty($actual),
            default => false,
        };
    }

    private function castScalar(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', $value);
        }

        return (string) $value;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value) && count($value) === 0) {
            return true;
        }

        return false;
    }

    private function inOperator(mixed $actual, mixed $ruleValue): bool
    {
        $allowedValues = is_array($ruleValue)
            ? $ruleValue
            : array_map('trim', explode(',', (string) $ruleValue));

        if (is_array($actual)) {
            return count(array_intersect($actual, $allowedValues)) > 0;
        }

        return in_array((string) $actual, $allowedValues, strict: true);
    }
}
