<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\SurveyField;

/**
 * @param  array<int, array{key: string, group?: string|null, randomize?: bool}>  $specs
 * @return list<SurveyField>
 */
function fieldsFromSpecs(array $specs): array
{
    return array_map(function (array $spec): SurveyField {
        $settings = [];
        if (($spec['group'] ?? null) !== null) {
            $settings['group'] = $spec['group'];
        }
        if ($spec['randomize'] ?? false) {
            $settings['randomize_in_group'] = true;
        }

        return new SurveyField([
            'field_key' => $spec['key'],
            'type' => SurveyFieldType::ShortText,
            'settings_json' => $settings,
        ]);
    }, $specs);
}

function arrangedKeys(array $specs, ?int $seed): array
{
    return array_map(
        fn (SurveyField $field): string => $field->field_key,
        SurveyField::arrangeForDisplay(fieldsFromSpecs($specs), $seed),
    );
}

it('keeps order unchanged when no group opts into randomization', function () {
    $specs = [
        ['key' => 'a', 'group' => 'G1'],
        ['key' => 'b', 'group' => 'G1'],
        ['key' => 'c'],
    ];

    expect(arrangedKeys($specs, 99))->toBe(['a', 'b', 'c']);
});

it('shuffles only the flagged group and leaves other questions in place', function () {
    $specs = [
        ['key' => 'intro'],
        ['key' => 'g1', 'group' => 'G', 'randomize' => true],
        ['key' => 'g2', 'group' => 'G', 'randomize' => true],
        ['key' => 'g3', 'group' => 'G', 'randomize' => true],
        ['key' => 'outro'],
    ];

    $keys = arrangedKeys($specs, 3);

    // 非題組題目位置不變
    expect($keys[0])->toBe('intro')
        ->and($keys[4])->toBe('outro')
        // 題組成員仍佔據原本三個位置，只是順序可能改變
        ->and(array_slice($keys, 1, 3))->toEqualCanonicalizing(['g1', 'g2', 'g3']);
});

it('is stable for the same seed and varies across seeds', function () {
    $specs = [
        ['key' => 'g1', 'group' => 'G', 'randomize' => true],
        ['key' => 'g2', 'group' => 'G', 'randomize' => true],
        ['key' => 'g3', 'group' => 'G', 'randomize' => true],
        ['key' => 'g4', 'group' => 'G', 'randomize' => true],
    ];

    expect(arrangedKeys($specs, 11))->toBe(arrangedKeys($specs, 11));

    $orders = collect(range(1, 12))
        ->map(fn (int $seed): string => implode(',', arrangedKeys($specs, $seed)))
        ->unique();

    expect($orders->count())->toBeGreaterThan(1);
});

it('does not shuffle a single-member group', function () {
    $specs = [
        ['key' => 'a', 'group' => 'Solo', 'randomize' => true],
        ['key' => 'b'],
    ];

    expect(arrangedKeys($specs, 5))->toBe(['a', 'b']);
});
