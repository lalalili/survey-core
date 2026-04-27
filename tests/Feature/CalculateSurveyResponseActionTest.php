<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Actions\CalculateSurveyResponseAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Listeners\DispatchSurveySubmittedWebhook;
use Lalalili\SurveyCore\Models\Survey;

function calculationSchema(array $optionOverrides = []): array
{
    return [
        'id' => 1,
        'title' => 'Calc Survey',
        'status' => 'draft',
        'version' => 1,
        'calculations' => [
            ['id' => 'calc_score', 'key' => 'score', 'label' => '總分', 'initial_value' => 0],
            ['id' => 'calc_risk', 'key' => 'risk', 'label' => '風險', 'initial_value' => 1, 'output_format' => 'grade', 'grade_map_json' => [
                ['min' => 0, 'max' => 3, 'label' => '低'],
                ['min' => 4, 'max' => 10, 'label' => '高'],
            ]],
        ],
        'pages' => [[
            'id' => 'page_1',
            'kind' => 'question',
            'title' => 'Page',
            'elements' => [[
                'id' => 'q_1',
                'type' => 'single_choice',
                'field_key' => 'choice',
                'label' => 'Choice',
                'description' => '',
                'required' => true,
                'placeholder' => null,
                'options' => [
                    array_merge(['id' => 'opt_1', 'label' => 'A', 'value' => 'a', 'score_delta_json' => ['score' => 5, 'risk' => 4]], $optionOverrides),
                    ['id' => 'opt_2', 'label' => 'B', 'value' => 'b'],
                ],
                'settings' => [],
            ]],
        ]],
    ];
}

it('calculates a single score from selected option deltas', function () {
    $survey = Survey::create(['title' => 'Score', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, calculationSchema());

    $result = app(CalculateSurveyResponseAction::class)->execute($survey->refresh(), ['choice' => 'a']);

    expect($result['score'])->toBe(5);
});

it('calculates multiple variables independently', function () {
    $survey = Survey::create(['title' => 'Multi', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, calculationSchema());

    $result = app(CalculateSurveyResponseAction::class)->execute($survey->refresh(), ['choice' => 'a']);

    expect($result['score'])->toBe(5)
        ->and($result['risk'])->toBe('高');
});

it('ignores options without score deltas', function () {
    $survey = Survey::create(['title' => 'Ignore', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, calculationSchema());

    $result = app(CalculateSurveyResponseAction::class)->execute($survey->refresh(), ['choice' => 'b']);

    expect($result['score'])->toBe(0)
        ->and($result['risk'])->toBe('低');
});

it('writes calculations_json when a response is submitted', function () {
    Event::fake([SurveySubmitted::class]);
    $survey = Survey::create(['title' => 'Submit', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, calculationSchema());
    $survey->update(['status' => SurveyStatus::Published]);

    $response = app(SubmitSurveyResponseAction::class)->execute($survey->refresh(), new SubmissionPayload(['choice' => 'a']));

    expect($response->calculations_json)->toMatchArray(['score' => 5, 'risk' => '高']);
});

it('includes calculations in webhook payload', function () {
    Http::fake(['*' => Http::response('OK', 200)]);
    Event::fake([SurveySubmitted::class]);
    config()->set('survey-core.webhooks.endpoints', ['https://example.com/hook']);
    $survey = Survey::create(['title' => 'Webhook Calc', 'status' => SurveyStatus::Published]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, calculationSchema());
    $survey->update(['status' => SurveyStatus::Published]);
    $response = app(SubmitSurveyResponseAction::class)->execute($survey->refresh(), new SubmissionPayload(['choice' => 'a']));

    app(DispatchSurveySubmittedWebhook::class)->handle(new SurveySubmitted($response, $survey));

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return ($data['calculations']['score'] ?? null) === 5;
    });
});
