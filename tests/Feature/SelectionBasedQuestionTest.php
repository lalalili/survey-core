<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;

function selectionBasedSchema(): array
{
    return [
        'id' => 1,
        'title' => 'Selection Based',
        'status' => 'draft',
        'version' => 1,
        'pages' => [[
            'id' => 'page_1',
            'title' => 'Page 1',
            'elements' => [
                [
                    'id' => 'q_src',
                    'type' => 'multiple_choice',
                    'field_key' => 'visited',
                    'label' => '去過哪些城市',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [
                        ['id' => 'o1', 'label' => '台北', 'value' => 'taipei'],
                        ['id' => 'o2', 'label' => '台中', 'value' => 'taichung'],
                        ['id' => 'o3', 'label' => '高雄', 'value' => 'kaohsiung'],
                    ],
                    'settings' => [],
                ],
                [
                    'id' => 'q_sel',
                    'type' => 'selection_based',
                    'field_key' => 'favorite',
                    'label' => '其中最喜歡哪些',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => ['source_field_key' => 'visited'],
                ],
            ],
        ]],
    ];
}

it('accepts selection_based elements during schema validation', function () {
    $validated = app(ValidateSurveyBuilderSchemaAction::class)->execute(selectionBasedSchema());

    $element = collect($validated['pages'][0]['elements'])->firstWhere('type', 'selection_based');

    expect($element)->not->toBeNull();
});

it('requires selection_based questions to select a source question', function () {
    $schema = selectionBasedSchema();
    data_set($schema, 'pages.0.elements.1.settings.source_field_key', null);

    try {
        app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
    } catch (SurveyValidationException $exception) {
        expect($exception->getErrors())
            ->toHaveKey('pages.0.elements.1.settings.source_field_key')
            ->and($exception->getErrors()['pages.0.elements.1.settings.source_field_key'])
            ->toContain('重複核選題請選擇來源題目。');

        return;
    }

    $this->fail('Expected schema validation to reject a missing selection source.');
});

it('requires selection_based sources to reference an earlier eligible question', function () {
    $schema = selectionBasedSchema();
    data_set($schema, 'pages.0.elements.1.settings.source_field_key', 'missing');

    expect(fn () => app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))
        ->toThrow(SurveyValidationException::class);
});

it('syncs selection_based source setting to the persisted field', function () {
    $survey = Survey::create(['title' => 'Selection Based', 'status' => SurveyStatus::Draft]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, selectionBasedSchema());
    // 存草稿只寫 draft_schema；survey_fields 要到發佈才同步。
    app(PublishSurveyAction::class)->execute($survey);

    $field = $survey->fields()->where('field_key', 'favorite')->first();

    expect($field)->not->toBeNull()
        ->and($field->type)->toBe(SurveyFieldType::SelectionBased)
        ->and($field->settings_json['source_field_key'])->toBe('visited');
});

it('stores a multi-value answer for a selection_based question', function () {
    $survey = Survey::create(['title' => 'Selection Based', 'status' => SurveyStatus::Published]);
    $source = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::MultipleChoice,
        'label' => '去過哪些城市',
        'field_key' => 'visited',
        'options_json' => [['value' => 'taipei'], ['value' => 'taichung'], ['value' => 'kaohsiung']],
        'sort_order' => 1,
    ]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SelectionBased,
        'label' => '其中最喜歡哪些',
        'field_key' => 'favorite',
        'settings_json' => ['source_field_key' => 'visited'],
        'sort_order' => 2,
    ]);
    $survey->load('fields');

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload([
            'visited' => ['taipei', 'taichung'],
            'favorite' => ['taipei'],
        ]),
    );

    $answer = SurveyAnswer::where('survey_response_id', $response->id)
        ->where('survey_field_id', $field->id)
        ->first();

    expect($answer)->not->toBeNull()
        ->and($answer->answer_json)->toBe(['taipei']);

    expect($source->fresh())->not->toBeNull();
});
