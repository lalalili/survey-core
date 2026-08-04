<?php

use Lalalili\SurveyCore\Support\ConditionGroupEvaluator;

/**
 * 顯示條件求值的一致性測試（PHP 側）。
 *
 * 與 `tests/js/conditionConsistency.test.js` 共用同一份 fixture。這一側斷言權威實作
 * `ConditionGroupEvaluator` 的行為，fixture 的 `expected` 即以此為準；JS 那一側再拿
 * 同一份檢查受訪者實際執行的公開填答頁是否跟得上。
 */

/**
 * @return array{answers: array<string, mixed>, cases: list<array<string, mixed>>}
 */
function conditionConsistencyFixture(): array
{
    $path = __DIR__.'/../Fixtures/condition-consistency.json';
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $answers = [];
    foreach ($decoded['answers'] as $fieldKey => $definition) {
        $answers[$fieldKey] = $definition['value'];
    }

    return ['answers' => $answers, 'cases' => $decoded['cases']];
}

it('evaluates every fixture case as the fixture records', function () {
    $fixture = conditionConsistencyFixture();

    expect($fixture['cases'])->not->toBeEmpty();

    foreach ($fixture['cases'] as $case) {
        expect(ConditionGroupEvaluator::passes($case['group'], $fixture['answers']))
            ->toBe($case['expected'], "案例「{$case['name']}」的 PHP 求值與 fixture 不符");
    }
});

it('covers every operator the evaluator supports', function () {
    $fixture = conditionConsistencyFixture();

    $covered = [];
    foreach ($fixture['cases'] as $case) {
        foreach ($case['group']['conditions'] ?? [] as $node) {
            foreach (isset($node['conditions']) ? $node['conditions'] : [$node] as $leaf) {
                $covered[] = $leaf['op'] ?? 'equals';
            }
        }
    }

    expect(array_values(array_unique($covered)))->toContain(
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'is_empty',
        'is_not_empty',
        'greater_than',
        'greater_than_or_equal',
        'between',
    );
});

it('compares loosely so that a numeric condition value matches a string answer', function () {
    // 送出的答案一律是字串（HTTP 表單），條件值卻可能在 schema 裡存成數字。
    $group = [
        'logic' => 'and',
        'conditions' => [['field_key' => 'score', 'op' => 'equals', 'value' => 0]],
    ];

    expect(ConditionGroupEvaluator::passes($group, ['score' => '0']))->toBeTrue();
});

it('treats an unanswered target as failing every operator except the emptiness checks', function () {
    $answers = ['target' => null];

    $group = fn (string $op, mixed $value = 'x'): array => [
        'logic' => 'and',
        'conditions' => [['field_key' => 'target', 'op' => $op, 'value' => $value]],
    ];

    expect(ConditionGroupEvaluator::passes($group('not_equals'), $answers))->toBeFalse();
    expect(ConditionGroupEvaluator::passes($group('not_contains'), $answers))->toBeFalse();
    expect(ConditionGroupEvaluator::passes($group('greater_than', -1), $answers))->toBeFalse();
    expect(ConditionGroupEvaluator::passes($group('is_empty'), $answers))->toBeTrue();
    expect(ConditionGroupEvaluator::passes($group('is_not_empty'), $answers))->toBeFalse();
});
