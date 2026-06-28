<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\SurveyField;

function makeChoiceField(array $settings = []): SurveyField
{
    return new SurveyField([
        'field_key' => 'choice',
        'type' => SurveyFieldType::SingleChoice,
        'options_json' => [
            ['label' => 'A', 'value' => 'a'],
            ['label' => 'B', 'value' => 'b'],
            ['label' => 'C', 'value' => 'c'],
            ['label' => 'D', 'value' => 'd'],
            ['label' => 'E', 'value' => 'e'],
        ],
        'settings_json' => $settings,
    ]);
}

it('keeps original option order when randomize is off', function () {
    $field = makeChoiceField();

    $values = array_column($field->displayOptions(123), 'value');

    expect($values)->toBe(['a', 'b', 'c', 'd', 'e']);
});

it('returns every option even when randomized', function () {
    $field = makeChoiceField(['randomize_options' => true]);

    $values = array_column($field->displayOptions(123), 'value');

    expect($values)->toHaveCount(5)
        ->and($values)->toContain('a', 'b', 'c', 'd', 'e');
});

it('produces a stable order for the same seed', function () {
    $field = makeChoiceField(['randomize_options' => true]);

    expect(array_column($field->displayOptions(42), 'value'))
        ->toBe(array_column($field->displayOptions(42), 'value'));
});

it('produces different orders for different seeds', function () {
    $field = makeChoiceField(['randomize_options' => true]);

    $orders = collect(range(1, 12))
        ->map(fn (int $seed): string => implode(',', array_column($field->displayOptions($seed), 'value')))
        ->unique();

    expect($orders->count())->toBeGreaterThan(1);
});

it('shuffles matrix rows only when randomize_rows is on', function () {
    $rows = [
        ['id' => 'r1', 'label' => '品質'],
        ['id' => 'r2', 'label' => '服務'],
        ['id' => 'r3', 'label' => '價格'],
    ];

    $stable = new SurveyField(['field_key' => 'm', 'settings_json' => ['matrix_rows' => $rows]]);
    expect(array_column($stable->displayMatrixRows(7), 'id'))->toBe(['r1', 'r2', 'r3']);

    $randomized = new SurveyField(['field_key' => 'm', 'settings_json' => ['matrix_rows' => $rows, 'randomize_rows' => true]]);
    $ids = array_column($randomized->displayMatrixRows(7), 'id');
    expect($ids)->toHaveCount(3)->and($ids)->toContain('r1', 'r2', 'r3');
});
