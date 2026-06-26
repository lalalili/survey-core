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
