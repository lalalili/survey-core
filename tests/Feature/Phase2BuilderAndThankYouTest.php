<?php

use Illuminate\Support\Facades\Route;
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
use Tests\TestCase;

$phase2BuilderTestCase = class_exists(TestCase::class)
    ? TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($phase2BuilderTestCase === TestCase::class) {
    uses($phase2BuilderTestCase);
}

beforeEach(function () use ($phase2BuilderTestCase): void {
    if ($phase2BuilderTestCase === TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }

    $this->app->bind(PersonalizationResolver::class, DefaultPersonalizationResolver::class);
    Route::post('/survey-test/{publicKey}/submit', [PublicSurveyController::class, 'submit']);
});

it('saves calculations, score deltas, matrix settings, show_if groups, and page jump rules from builder schema', function (): void {
    $survey = Survey::create(['title' => 'Builder', 'status' => SurveyStatus::Draft]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, [
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

    $survey->refresh()->load('calculations', 'fields', 'pages');

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
