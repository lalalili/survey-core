<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;

beforeEach(function (): void {
    Route::get('/survey/{publicKey}', [PublicSurveyController::class, 'show'])->name('survey.show');
    Route::getRoutes()->refreshNameLookups();
});

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
    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, pageKindSchema([
        array_merge(kindQuestionPage('welcome', 'welcome'), [
            'welcome_settings' => ['cta_label' => '開始', 'estimated_time_minutes' => 3, 'subtitle' => '前言'],
            'elements' => [],
        ]),
        kindQuestionPage('page_1'),
    ]));
    app(PublishSurveyAction::class)->execute($saved);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('id="welcome-screen"', false)
        ->assertDontSeeText('歡迎頁')
        ->assertDontSeeText('前言')
        ->assertSee('id="survey-form"', false)
        ->assertSee('hidden', false);
});

it('renders rich welcome and thank-you pages in the published css layout', function () {
    config(['survey-core.frontend.css' => 'published']);

    $survey = Survey::create([
        'title' => 'Published Layout',
        'status' => SurveyStatus::Draft,
        'allow_anonymous' => true,
    ]);
    $schema = pageKindSchema([
        array_merge(kindQuestionPage('welcome', 'welcome'), [
            'welcome_settings' => [
                'enabled' => true,
                'content' => '<h2 style="text-align: center"><span style="color: #2563eb">歡迎填寫</span></h2>',
                'cta_label' => '立即開始',
                'estimated_time_minutes' => 3,
            ],
            'elements' => [],
        ]),
        kindQuestionPage('page_1'),
        kindQuestionPage('page_2'),
        array_merge(kindQuestionPage('thanks', 'thank_you'), [
            'thank_you_settings' => [
                'enabled' => true,
                'message' => '<p style="text-align: right"><span style="color: #16a34a">填寫完成</span></p>',
            ],
        ]),
    ]);
    $schema['settings'] = ['password' => 'secret'];

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    app(PublishSurveyAction::class)->execute($saved);

    $response = $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('id="welcome-screen"', false)
        ->assertSee('<h2 style="text-align: center"><span style="color: #2563eb">歡迎填寫</span></h2>', false)
        ->assertSeeText('預估填寫時間：約 3 分鐘')
        ->assertSee('id="btn-start"', false)
        ->assertSeeText('立即開始')
        ->assertSee('id="success-text" class="survey-rich-content"', false)
        ->assertSee('<p style="text-align: right"><span style="color: #16a34a">填寫完成</span></p>', false);

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@id="after-gate"]//*[@id="welcome-screen"]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="after-gate"]//*[@id="success-message"]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="after-gate"]//*[@id="page-indicator" and contains(concat(" ", normalize-space(@class), " "), " survey-hidden ")]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="after-gate"]//*[@id="survey-form" and contains(concat(" ", normalize-space(@class), " "), " survey-hidden ")]'))->toHaveCount(1);
});
