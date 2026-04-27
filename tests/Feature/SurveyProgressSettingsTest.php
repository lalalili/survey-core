<?php

use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

if (! function_exists('kindQuestionPage')) {
function kindQuestionPage(string $id, string $kind = 'question', bool $required = false): array
{
    return [
        'id' => $id,
        'kind' => $kind,
        'title' => $id,
        'elements' => [[
            'id' => 'q_'.$id,
            'type' => 'short_text',
            'field_key' => 'field_'.$id,
            'label' => 'Field '.$id,
            'description' => '',
            'required' => $required,
            'placeholder' => null,
            'options' => [],
            'settings' => [],
        ]],
    ];
}
}

function progressSchema(string $mode = 'bar', int $estimatedMinutes = 5): array
{
    return [
        'id' => 1,
        'title' => 'Progress Survey',
        'status' => 'draft',
        'version' => 1,
        'settings' => ['progress' => ['mode' => $mode, 'show_estimated_time' => true]],
        'pages' => [
            [
                'id' => 'welcome',
                'kind' => 'welcome',
                'title' => 'Welcome',
                'welcome_settings' => ['cta_label' => 'Start', 'estimated_time_minutes' => $estimatedMinutes, 'subtitle' => 'Intro'],
                'elements' => [],
            ],
            kindQuestionPage('page_1'),
            kindQuestionPage('page_2'),
        ],
    ];
}

it('does not render progress markup when mode is none', function () {
    $survey = Survey::create(['title' => 'None', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, progressSchema('none'));
    $survey->update(['status' => SurveyStatus::Published]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertDontSee('id="page-indicator"', false);
});

it('renders a progress element when mode is bar', function () {
    $survey = Survey::create(['title' => 'Bar', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, progressSchema('bar'));
    $survey->update(['status' => SurveyStatus::Published]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('id="progress-bar"', false);
});

it('renders page count text when mode is percent', function () {
    $survey = Survey::create(['title' => 'Percent', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, progressSchema('percent'));
    $survey->update(['status' => SurveyStatus::Published]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('第 <span id="current-page-label">1</span> 頁，共 2 頁', false);
});

it('shows estimated time on the welcome screen', function () {
    $survey = Survey::create(['title' => 'Estimate', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, progressSchema('bar', 7));
    $survey->update(['status' => SurveyStatus::Published]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('約 7 分鐘');
});

it('stores progress settings through autosave', function () {
    $survey = Survey::create(['title' => 'Stored', 'status' => SurveyStatus::Draft]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, progressSchema('steps'));

    expect($survey->refresh()->settings_json['progress']['mode'])->toBe('steps');
});
