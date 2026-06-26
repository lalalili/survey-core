<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Actions\Triggers\DispatchHttpTriggerAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

it('only resolves allowed environment tokens in http trigger headers', function (): void {
    config()->set('survey-core.triggers.header_env_keys', ['SURVEY_TRIGGER_AUTH_TOKEN']);

    $_SERVER['SURVEY_TRIGGER_AUTH_TOKEN'] = 'allowed-secret';
    $_SERVER['SURVEY_FORBIDDEN_SECRET'] = 'blocked-secret';

    try {
        Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);

        $survey = Survey::create([
            'title' => 'Trigger Survey',
            'status' => SurveyStatus::Published,
        ]);

        $rule = SurveyTriggerRule::create([
            'survey_id' => $survey->id,
            'name' => 'HTTP trigger',
            'is_active' => true,
            'rule_tree_json' => ['op' => 'AND', 'children' => []],
            'actions_json' => [],
        ]);

        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'is_test' => false,
        ]);

        $dispatch = SurveyTriggerDispatch::create([
            'survey_trigger_rule_id' => $rule->id,
            'survey_response_id' => $response->id,
            'status' => TriggerDispatchStatus::Pending,
        ]);

        app(DispatchHttpTriggerAction::class)->execute($dispatch, [
            'endpoint' => 'https://hooks.example.test/survey',
            'headers' => [
                'Authorization' => 'Bearer {{env.SURVEY_TRIGGER_AUTH_TOKEN}}',
                'X-Blocked-Secret' => '{{env.SURVEY_FORBIDDEN_SECRET}}',
            ],
        ], ['response_id' => $response->id]);

        $dispatch->refresh();

        expect($dispatch->status)->toBe(TriggerDispatchStatus::Sent);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer allowed-secret')
                && $request->hasHeader('X-Blocked-Secret', '');
        });
    } finally {
        unset($_SERVER['SURVEY_TRIGGER_AUTH_TOKEN'], $_SERVER['SURVEY_FORBIDDEN_SECRET']);
    }
});

it('skips http trigger external calls when external communications are disabled', function (): void {
    config()->set('external-communications.enabled', false);
    Http::preventStrayRequests();

    $survey = Survey::create([
        'title' => 'Trigger Survey',
        'status' => SurveyStatus::Published,
    ]);

    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => 'HTTP trigger',
        'is_active' => true,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [],
    ]);

    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'is_test' => false,
    ]);

    $dispatch = SurveyTriggerDispatch::create([
        'survey_trigger_rule_id' => $rule->id,
        'survey_response_id' => $response->id,
        'status' => TriggerDispatchStatus::Pending,
    ]);

    app(DispatchHttpTriggerAction::class)->execute($dispatch, [
        'endpoint' => 'https://hooks.example.test/survey',
    ], ['response_id' => $response->id]);

    $dispatch->refresh();

    expect($dispatch->status)->toBe(TriggerDispatchStatus::Skipped)
        ->and($dispatch->error)->toBe('HTTP trigger disabled by external communications setting.')
        ->and($dispatch->response_json)->toMatchArray(['status' => 'skipped']);

    Http::assertNothingSent();
});
