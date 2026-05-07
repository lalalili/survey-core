<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\SurveyCoreServiceProvider;
use Tests\TestCase;

$surveyPageKindTestCase = class_exists(TestCase::class)
    ? TestCase::class
    : null;

if ($surveyPageKindTestCase !== null) {
    uses($surveyPageKindTestCase);

    beforeEach(function (): void {
        $this->app->register(SurveyCoreServiceProvider::class);
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();

        Route::get('/survey/{publicKey}', [PublicSurveyController::class, 'show'])->name('survey.show');
        Route::getRoutes()->refreshNameLookups();
    });
}

if (! function_exists('pageKindSchema')) {
    function pageKindSchema(array $pages): array
    {
        return [
            'id' => 1,
            'title' => 'Kind Survey',
            'status' => 'draft',
            'version' => 1,
            'pages' => $pages,
        ];
    }
}

if (! function_exists('kindQuestionPage')) {
    function kindQuestionPage(string $id, string $kind = 'question', bool $required = false): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $id,
            'elements' => $kind === 'thank_you' ? [] : [[
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

it('rejects a welcome page that is not first', function () {
    app(ValidateSurveyBuilderSchemaAction::class)->execute(pageKindSchema([
        kindQuestionPage('page_1'),
        kindQuestionPage('welcome', 'welcome'),
    ]));
})->throws(SurveyValidationException::class);

it('rejects a thank-you page that is not last', function () {
    app(ValidateSurveyBuilderSchemaAction::class)->execute(pageKindSchema([
        kindQuestionPage('thanks', 'thank_you'),
        kindQuestionPage('page_1'),
    ]));
})->throws(SurveyValidationException::class);

it('rejects duplicate welcome pages', function () {
    app(ValidateSurveyBuilderSchemaAction::class)->execute(pageKindSchema([
        kindQuestionPage('welcome_1', 'welcome'),
        kindQuestionPage('page_1'),
        kindQuestionPage('welcome_2', 'welcome'),
    ]));
})->throws(SurveyValidationException::class);

it('accepts a survey without welcome or thank-you pages', function () {
    $validated = app(ValidateSurveyBuilderSchemaAction::class)->execute(pageKindSchema([
        kindQuestionPage('page_1'),
    ]));

    expect($validated['pages'][0]['id'])->toBe('page_1');
});

it('syncs page kind to the survey_pages table', function () {
    $survey = Survey::create(['title' => 'Kind Sync', 'status' => SurveyStatus::Draft]);
    $schema = pageKindSchema([
        kindQuestionPage('welcome', 'welcome'),
        kindQuestionPage('page_1'),
        kindQuestionPage('thanks', 'thank_you'),
    ]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    expect($survey->pages()->where('page_key', 'welcome')->first()->kind)->toBe(SurveyPageKind::Welcome)
        ->and($survey->pages()->where('page_key', 'thanks')->first()->kind)->toBe(SurveyPageKind::ThankYou);
});

it('renders the welcome screen before the form', function () {
    $survey = Survey::create(['title' => 'Runtime', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, pageKindSchema([
        array_merge(kindQuestionPage('welcome', 'welcome'), [
            'welcome_settings' => ['cta_label' => '開始', 'estimated_time_minutes' => 3, 'subtitle' => '前言'],
            'elements' => [],
        ]),
        kindQuestionPage('page_1'),
    ]));
    $survey->update(['status' => SurveyStatus::Published]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('id="welcome-screen"', false)
        ->assertSee('id="survey-form"', false)
        ->assertSee('hidden', false);
});
