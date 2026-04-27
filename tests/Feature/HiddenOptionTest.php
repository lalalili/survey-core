<?php

use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

require __DIR__.'/Phase3TestSupport.php';

it('does not render hidden options on the public page', function () {
    $survey = Survey::create(['title' => 'Hidden Option', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'options_json' => [
            ['label' => 'Visible', 'value' => 'visible'],
            ['label' => 'Old', 'value' => 'old', 'is_hidden' => true],
        ],
        'sort_order' => 1,
    ]);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('Visible')
        ->assertDontSee('Old');
});

it('keeps historical answers for hidden option values readable', function () {
    $survey = Survey::create(['title' => 'History', 'status' => SurveyStatus::Published]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'options_json' => [['label' => 'Old', 'value' => 'old', 'is_hidden' => true]],
        'sort_order' => 1,
    ]);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $answer = SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_field_id' => $field->id, 'answer_text' => 'old']);

    expect($answer->fresh()->getValue())->toBe('old');
});

it('filters hidden options from options for display', function () {
    $field = new SurveyField([
        'options_json' => [
            ['label' => 'Visible', 'value' => 'visible'],
            ['label' => 'Hidden', 'value' => 'hidden', 'is_hidden' => true],
        ],
    ]);

    expect($field->optionsForDisplay())->toBe(['visible' => 'Visible']);
});

it('syncs hidden option settings from builder schema', function () {
    $survey = Survey::create(['title' => 'Sync Hidden Option', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, [
        'pages' => [[
            'id' => 'page_1',
            'title' => 'Page 1',
            'elements' => [[
                'id' => 'q1',
                'type' => 'single_choice',
                'field_key' => 'choice',
                'label' => 'Choice',
                'description' => '',
                'required' => false,
                'settings' => [],
                'options' => [['id' => 'old', 'label' => 'Old', 'value' => 'old', 'is_hidden' => true]],
            ]],
        ]],
    ]);

    expect($survey->fields()->first()->options_json[0]['is_hidden'])->toBeTrue();
});
