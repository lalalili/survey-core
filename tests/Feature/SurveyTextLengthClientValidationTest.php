<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function textLengthValidationSurvey(): Survey
{
    $survey = Survey::create([
        'title' => '文字字數前端驗證',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '一句話心得',
        'field_key' => 'summary',
        'validation_rules' => [
            'min_length' => 5,
            'min_chinese_length' => 2,
            'max_length' => 20,
        ],
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::LongText,
        'label' => '完整心得',
        'field_key' => 'feedback',
        'validation_rules' => [
            'min_length' => 10,
            'min_chinese_length' => 4,
            'max_length' => 100,
        ],
        'sort_order' => 2,
    ]);

    return $survey;
}

it('renders text length metadata and accessible errors in both frontend modes', function (string $frontendMode) {
    config(['survey-core.frontend.css' => $frontendMode]);

    $this->get(route('survey.show', textLengthValidationSurvey()->public_key))
        ->assertSuccessful()
        ->assertSee('data-field-key="summary"', false)
        ->assertSee('data-min-length="5"', false)
        ->assertSee('data-min-chinese-length="2"', false)
        ->assertSee('data-max-length="20"', false)
        ->assertSee('aria-describedby="field-error-summary"', false)
        ->assertSee('id="field-error-summary"', false)
        ->assertSee('role="status" aria-live="polite"', false)
        ->assertSee('data-field-key="feedback"', false)
        ->assertSee('data-min-length="10"', false)
        ->assertSee('data-min-chinese-length="4"', false)
        ->assertSee('data-max-length="100"', false)
        ->assertSee('aria-describedby="field-error-feedback"', false)
        ->assertSee('id="field-error-feedback"', false);
})->with([
    'cdn' => 'cdn',
    'published' => 'published',
]);
