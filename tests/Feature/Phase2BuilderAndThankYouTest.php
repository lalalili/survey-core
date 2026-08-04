<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCalculation;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Services\DefaultPersonalizationResolver;

beforeEach(function (): void {
    $this->app->bind(PersonalizationResolver::class, DefaultPersonalizationResolver::class);
    Route::post('/survey-test/{publicKey}/submit', [PublicSurveyController::class, 'submit']);
});

it('saves calculations, score deltas, matrix settings, show_if groups, and page jump rules from builder schema', function (): void {
    $survey = Survey::create(['title' => 'Builder', 'status' => SurveyStatus::Draft]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, [
        'title' => 'Builder',
        'settings' => ['progress' => ['mode' => 'bar', 'show_estimated_time' => true]],
        'calculations' => [[
            'id' => 'calc_score',
            'key' => 'score',
            'label' => 'Score',
            'initial_value' => 0,
            'output_format' => 'number',
        ]],
        'pages' => [[
            'id' => 'page_1',
            'kind' => 'question',
            'title' => 'One',
            'jump_rules' => [[
                'condition' => ['logic' => 'and', 'conditions' => [['field_key' => 'choice', 'op' => 'equals', 'value' => 'a']]],
                'action' => ['type' => 'end_survey'],
            ]],
            'elements' => [[
                'id' => 'q1',
                'type' => 'single_choice',
                'field_key' => 'choice',
                'label' => 'Choice',
                'description' => '',
                'required' => true,
                'placeholder' => null,
                'settings' => [],
                'options' => [['id' => 'a', 'label' => 'A', 'value' => 'a', 'score_delta_json' => ['score' => 5]]],
            ], [
                'id' => 'q2',
                'type' => 'matrix_single',
                'field_key' => 'matrix',
                'label' => 'Matrix',
                'description' => '',
                'required' => true,
                'placeholder' => null,
                'settings' => [],
                'options' => [],
                'matrix_rows' => [['id' => 'row', 'label' => 'Row']],
                'matrix_cols' => [['id' => 'col', 'label' => 'Col']],
                'show_if' => ['logic' => 'and', 'conditions' => [['field_key' => 'choice', 'op' => 'equals', 'value' => 'a']]],
            ]],
        ]],
    ]);

    $survey = app(PublishSurveyAction::class)->execute($saved)->load('calculations', 'fields', 'pages');

    expect($survey->calculations)->toHaveCount(1)
        ->and($survey->fields->firstWhere('field_key', 'choice')->options_json[0]['score_delta_json']['score'])->toBe(5)
        ->and($survey->fields->firstWhere('field_key', 'matrix')->settings_json['matrix_rows'][0]['id'])->toBe('row')
        ->and($survey->fields->firstWhere('field_key', 'matrix')->show_if_field_key)->toBe('choice')
        ->and($survey->pages->first()->settings_json['jump_rules'][0]['action']['type'])->toBe('end_survey');
});

it('interpolates calculations and routes to a branched thank-you page', function (): void {
    $survey = Survey::create([
        'title' => 'Thanks',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'settings_json' => ['thank_you_branches' => [[
            'condition' => ['calc_key' => 'score', 'op' => '>=', 'value' => 80],
            'page_id' => 'thanks_high',
        ]]],
    ]);

    SurveyCalculation::create([
        'survey_id' => $survey->id,
        'key' => 'score',
        'label' => 'Score',
        'initial_value' => 0,
        'output_format' => 'number',
    ]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'is_required' => true,
        'options_json' => [[
            'id' => 'high',
            'label' => 'High',
            'value' => 'high',
            'score_delta_json' => ['score' => 80],
        ]],
        'sort_order' => 1,
    ]);

    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks_default',
        'title' => 'Default',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 1,
        'settings_json' => ['thank_you' => ['message' => '分數 {{ calc.score }}']],
    ]);
    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks_high',
        'title' => 'High',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 2,
        'settings_json' => ['thank_you' => ['message' => '高分 {{ calc.score }}']],
    ]);

    $response = $this->postJson('/survey-test/'.$survey->public_key.'/submit', ['answers' => ['choice' => 'high']]);

    $response->assertCreated()
        ->assertJsonPath('message', '高分 80')
        ->assertJsonPath('thank_you_page_id', 'thanks_high');
});

it('interpolates calculations when rich editor autolink split the token', function (): void {
    $survey = Survey::create([
        'title' => 'Autolink Thanks',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyCalculation::create([
        'survey_id' => $survey->id,
        'key' => 'total_score',
        'label' => '總分',
        'initial_value' => 0,
        'output_format' => 'number',
    ]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'is_required' => true,
        'options_json' => [[
            'id' => 'high',
            'label' => 'High',
            'value' => 'high',
            'score_delta_json' => ['total_score' => 80],
        ]],
        'sort_order' => 1,
    ]);
    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks',
        'title' => 'Thanks',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 1,
        'settings_json' => [
            'thank_you' => [
                'message' => '<p>分數 {{ <a target="_blank" rel="noopener noreferrer" href="http://calc.total">calc.total</a>_score }}</p>',
            ],
        ],
    ]);

    $response = $this->postJson('/survey-test/'.$survey->public_key.'/submit', ['answers' => ['choice' => 'high']]);

    $response->assertCreated()
        ->assertJsonPath('message', '<p>分數 80</p>');
});

it('interpolates calculation variable chips from the rich editor', function (): void {
    $survey = Survey::create([
        'title' => 'Variable Chip Thanks',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyCalculation::create([
        'survey_id' => $survey->id,
        'key' => 'total_score',
        'label' => '總分',
        'initial_value' => 0,
        'output_format' => 'number',
    ]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'is_required' => true,
        'options_json' => [[
            'id' => 'high',
            'label' => 'High',
            'value' => 'high',
            'score_delta_json' => ['total_score' => 80],
        ]],
        'sort_order' => 1,
    ]);
    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks',
        'title' => 'Thanks',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 1,
        'settings_json' => [
            'thank_you' => [
                'message' => '<p>分數 <span class="survey-variable-token" data-variable-token="{{ calc.total_score }}" data-variable-label="總分" contenteditable="false">總分<code>calc.total_score</code></span></p>',
            ],
        ],
    ]);

    $response = $this->postJson('/survey-test/'.$survey->public_key.'/submit', ['answers' => ['choice' => 'high']]);

    $response->assertCreated()
        ->assertJsonPath('message', '<p>分數 80</p>');
});

it('keeps response number interpolation working with calculation token handling', function (): void {
    $survey = Survey::create([
        'title' => 'Response Number Thanks',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'settings_json' => ['response_number' => true],
    ]);

    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks',
        'title' => 'Thanks',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 1,
        'settings_json' => ['thank_you' => ['message' => '編號 {{response_number}}']],
    ]);

    $response = $this->postJson('/survey-test/'.$survey->public_key.'/submit', ['answers' => []]);
    $responseNumber = $response->json('response_number');

    expect($responseNumber)->toBeString()->not->toBe('');
    $response->assertCreated()
        ->assertJsonPath('message', '編號 '.$responseNumber);
});

it('saves raw calculation tokens without creating autolink markup', function (): void {
    $survey = Survey::create(['title' => 'Raw Token', 'status' => SurveyStatus::Draft]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, [
        'title' => 'Raw Token',
        'settings' => [],
        'calculations' => [[
            'id' => 'calc_total',
            'key' => 'total_score',
            'label' => '總分',
            'initial_value' => 0,
            'output_format' => 'number',
        ]],
        'pages' => [[
            'id' => 'thanks',
            'kind' => 'thank_you',
            'title' => 'Thanks',
            'thank_you_settings' => [
                'message' => '<p>{{ calc.total_score }}</p>',
            ],
            'elements' => [],
        ]],
    ]);

    $survey = app(PublishSurveyAction::class)->execute($saved)->load('pages');
    $message = $survey->pages->firstWhere('page_key', 'thanks')?->settings_json['thank_you']['message'] ?? '';

    expect($message)->toContain('{{ calc.total_score }}')
        ->not->toContain('href="http://calc.total"');
});
