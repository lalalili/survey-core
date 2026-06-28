<?php

use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function groupedField(array $settings = []): SurveyField
{
    return new SurveyField([
        'field_key' => 'fruit',
        'type' => SurveyFieldType::SingleChoice,
        'options_json' => [
            ['label' => '蘋果', 'value' => 'apple', 'group' => '水果'],
            ['label' => '香蕉', 'value' => 'banana', 'group' => '水果'],
            ['label' => '紅蘿蔔', 'value' => 'carrot', 'group' => '蔬菜'],
            ['label' => '菠菜', 'value' => 'spinach', 'group' => '蔬菜'],
            ['label' => '其他', 'value' => 'other'],
        ],
        'settings_json' => $settings,
    ]);
}

it('groups options by their group label preserving first-seen order', function () {
    $groups = groupedField()->displayOptionGroups();

    expect(array_column($groups, 'label'))->toBe(['水果', '蔬菜', '']);
    expect(array_column($groups[0]['options'], 'value'))->toBe(['apple', 'banana']);
    expect(array_column($groups[2]['options'], 'value'))->toBe(['other']);
});

it('keeps options contiguous within their group when flattened', function () {
    $values = array_column(groupedField()->displayOptions(123), 'value');

    // 水果在一起、蔬菜在一起、未分組在最後。
    expect(array_slice($values, 0, 2))->toEqualCanonicalizing(['apple', 'banana'])
        ->and(array_slice($values, 2, 2))->toEqualCanonicalizing(['carrot', 'spinach'])
        ->and($values[4])->toBe('other');
});

it('shuffles within groups but keeps group boundaries when randomize_options is on', function () {
    $values = array_column(groupedField(['randomize_options' => true])->displayOptions(7), 'value');

    expect(array_slice($values, 0, 2))->toEqualCanonicalizing(['apple', 'banana'])
        ->and(array_slice($values, 2, 2))->toEqualCanonicalizing(['carrot', 'spinach']);
});

it('exposes each option group label through normalized options', function () {
    expect(groupedField()->normalizedOptions()[0]['group'])->toBe('水果')
        ->and(groupedField()->normalizedOptions()[4]['group'])->toBeNull();
});

it('persists option group labels through the builder sync', function () {
    $survey = Survey::create(['title' => 'Grouped', 'status' => SurveyStatus::Draft]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, [
        'id' => $survey->id,
        'title' => 'Grouped',
        'status' => 'draft',
        'version' => 1,
        'pages' => [[
            'id' => 'page_1',
            'title' => 'Page 1',
            'elements' => [[
                'id' => 'q1',
                'type' => 'single_choice',
                'field_key' => 'fruit',
                'label' => '選一個',
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'settings' => ['randomize_option_groups' => true],
                'options' => [
                    ['id' => 'o1', 'label' => '蘋果', 'value' => 'apple', 'group' => '水果'],
                    ['id' => 'o2', 'label' => '紅蘿蔔', 'value' => 'carrot', 'group' => '蔬菜'],
                ],
            ]],
        ]],
    ]);

    $field = $survey->fields()->where('field_key', 'fruit')->first();

    expect($field->options_json[0]['group'])->toBe('水果')
        ->and($field->settings_json['randomize_option_groups'])->toBeTrue();
});
