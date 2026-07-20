<?php

use Lalalili\SurveyCore\Support\ConditionGroupEvaluator;

function leaf(string $field, string $value, string $op = 'equals'): array
{
    return ['field_key' => $field, 'op' => $op, 'value' => $value];
}

it('evaluates a flat AND group', function (): void {
    $group = ['logic' => 'and', 'conditions' => [leaf('a', '1'), leaf('b', '2')]];

    expect(ConditionGroupEvaluator::passes($group, ['a' => '1', 'b' => '2']))->toBeTrue();
    expect(ConditionGroupEvaluator::passes($group, ['a' => '1', 'b' => '9']))->toBeFalse();
});

it('evaluates a flat OR group', function (): void {
    $group = ['logic' => 'or', 'conditions' => [leaf('a', '1'), leaf('b', '2')]];

    expect(ConditionGroupEvaluator::passes($group, ['a' => '9', 'b' => '2']))->toBeTrue();
    expect(ConditionGroupEvaluator::passes($group, ['a' => '9', 'b' => '9']))->toBeFalse();
});

it('evaluates nested groups: (A=1 OR A=2) AND (B=3 OR B=4)', function (): void {
    $group = [
        'logic' => 'and',
        'conditions' => [
            ['logic' => 'or', 'conditions' => [leaf('a', '1'), leaf('a', '2')]],
            ['logic' => 'or', 'conditions' => [leaf('b', '3'), leaf('b', '4')]],
        ],
    ];

    expect(ConditionGroupEvaluator::passes($group, ['a' => '2', 'b' => '4']))->toBeTrue();
    expect(ConditionGroupEvaluator::passes($group, ['a' => '2', 'b' => '9']))->toBeFalse();
    expect(ConditionGroupEvaluator::passes($group, ['a' => '9', 'b' => '4']))->toBeFalse();
});

it('mixes leaf conditions and nested groups at the same level', function (): void {
    $group = [
        'logic' => 'and',
        'conditions' => [
            leaf('country', 'TW'),
            ['logic' => 'or', 'conditions' => [leaf('plan', 'pro'), leaf('plan', 'enterprise')]],
        ],
    ];

    expect(ConditionGroupEvaluator::passes($group, ['country' => 'TW', 'plan' => 'enterprise']))->toBeTrue();
    expect(ConditionGroupEvaluator::passes($group, ['country' => 'JP', 'plan' => 'enterprise']))->toBeFalse();
    expect(ConditionGroupEvaluator::passes($group, ['country' => 'TW', 'plan' => 'free']))->toBeFalse();
});

it('treats an empty group as visible (backward compatible)', function (): void {
    expect(ConditionGroupEvaluator::passes(['logic' => 'and', 'conditions' => []], []))->toBeTrue();
});

/**
 * 目標題目未作答時，條件一律不成立（is_empty / is_not_empty 除外）。
 * 少了這道守衛，not_equals / not_contains 會在使用者還沒作答時就成立，
 * 讓被條件控制的題目或頁面跳轉提前觸發。
 */
it('never passes a condition while the target question is unanswered', function (string $op, mixed $value): void {
    $group = ['logic' => 'and', 'conditions' => [['field_key' => 'rating', 'op' => $op, 'value' => $value]]];

    expect(ConditionGroupEvaluator::passes($group, []))->toBeFalse()
        ->and(ConditionGroupEvaluator::passes($group, ['rating' => null]))->toBeFalse()
        ->and(ConditionGroupEvaluator::passes($group, ['rating' => '']))->toBeFalse()
        ->and(ConditionGroupEvaluator::passes($group, ['rating' => []]))->toBeFalse();
})->with([
    'equals' => ['equals', '5'],
    'not_equals' => ['not_equals', '5'],
    'contains' => ['contains', '5'],
    'not_contains' => ['not_contains', '5'],
    'greater_than' => ['greater_than', '3'],
    'less_than' => ['less_than', '3'],
    'between' => ['between', ['min' => 0, 'max' => 10]],
]);

it('still evaluates emptiness operators on an unanswered question', function (): void {
    $isEmpty = ['logic' => 'and', 'conditions' => [['field_key' => 'rating', 'op' => 'is_empty']]];
    $isNotEmpty = ['logic' => 'and', 'conditions' => [['field_key' => 'rating', 'op' => 'is_not_empty']]];

    expect(ConditionGroupEvaluator::passes($isEmpty, []))->toBeTrue()
        ->and(ConditionGroupEvaluator::passes($isNotEmpty, []))->toBeFalse()
        ->and(ConditionGroupEvaluator::passes($isEmpty, ['rating' => '4']))->toBeFalse()
        ->and(ConditionGroupEvaluator::passes($isNotEmpty, ['rating' => '4']))->toBeTrue();
});

it('treats a zero answer as answered, not blank', function (): void {
    $lessThan = ['logic' => 'and', 'conditions' => [['field_key' => 'rating', 'op' => 'less_than', 'value' => '3']]];

    expect(ConditionGroupEvaluator::passes($lessThan, ['rating' => 0]))->toBeTrue()
        ->and(ConditionGroupEvaluator::passes($lessThan, ['rating' => '0']))->toBeTrue();
});
