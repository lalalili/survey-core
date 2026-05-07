<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveyStarted;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseConsent;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;
use Lalalili\SurveyCore\Models\SurveyToken;

require __DIR__.'/Phase3TestSupport.php';

function commercialSurveyCoreSurvey(array $attributes = [], array $settings = []): Survey
{
    $survey = Survey::create(array_merge([
        'title' => 'Commercial survey',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'settings_json' => array_replace_recursive([
            'security' => ['min_submission_ms' => 0],
        ], $settings),
    ], $attributes));

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'name',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    return $survey->load('fields');
}

it('keeps password-protected survey secrets out of the public HTML and unlocks server-side', function (): void {
    $survey = commercialSurveyCoreSurvey(settings: [
        'password' => 'launch-code',
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertDontSee('launch-code');

    $this->postJson(route('survey.password', $survey->public_key), [
        'password' => 'wrong',
    ])->assertUnprocessable();

    $this->postJson(route('survey.password', $survey->public_key), [
        'password' => 'launch-code',
    ])->assertSuccessful();

    $this->withSession(['survey-core.password.'.$survey->id => true]);

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
        '_password' => 'launch-code',
    ])->assertCreated();
});

it('rejects anonymous public submissions when the survey requires a token', function (): void {
    $survey = commercialSurveyCoreSurvey([
        'allow_anonymous' => false,
    ]);

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
    ])->assertForbidden();
});

it('verifies turnstile tokens server-side before accepting a response', function (): void {
    config()->set('survey-core.turnstile.secret_key', 'secret');

    $survey = commercialSurveyCoreSurvey(settings: [
        'anomaly' => ['turnstile' => true],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify*' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true]),
    ]);

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
        '_turnstile_token' => 'bad-token',
    ])->assertUnprocessable();

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
        '_turnstile_token' => 'good-token',
    ])->assertCreated();
});

it('requires terms acceptance and records the consent with the response', function (): void {
    $survey = commercialSurveyCoreSurvey(settings: [
        'terms_text' => 'I agree to the campaign terms.',
        'terms_version' => 'campaign-2026-05',
    ]);

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
    ])->assertUnprocessable();

    $this->postJson(route('survey.submit', $survey->public_key), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
        '_terms_accepted' => true,
    ])->assertCreated();

    $response = SurveyResponse::firstOrFail();
    $consent = SurveyResponseConsent::where('survey_response_id', $response->id)->firstOrFail();

    expect($consent->type)->toBe('terms')
        ->and($consent->version)->toBe('campaign-2026-05');
});

it('attaches collector attribution to submitted responses', function (): void {
    $survey = commercialSurveyCoreSurvey();
    $collector = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'qr_code',
        'name' => 'Event QR',
        'slug' => 'event-qr',
        'tracking_json' => ['utm_campaign' => 'expo'],
    ]);

    $this->get(route('survey.collector.show', $collector->slug))
        ->assertSuccessful();

    $this->postJson(route('survey.submit', [
        'publicKey' => $survey->public_key,
        'collector' => $collector->slug,
    ]), [
        'answers' => ['name' => 'Alice'],
        '_elapsed_ms' => 1000,
    ])->assertCreated();

    expect(SurveyResponse::firstOrFail()->survey_collector_id)->toBe($collector->id);
});

it('records started events and dispatches SurveyStarted with token recipient context', function (): void {
    Event::fake([SurveyStarted::class]);

    $survey = commercialSurveyCoreSurvey([
        'allow_anonymous' => false,
    ]);
    $recipient = SurveyRecipient::create([
        'survey_id' => $survey->id,
        'email' => 'demo@example.com',
    ]);
    $token = SurveyToken::create([
        'survey_id' => $survey->id,
        'survey_recipient_id' => $recipient->id,
        'token' => 'token-123',
    ]);
    $collector = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'email_invite',
        'name' => 'Email invite',
        'slug' => 'email-invite',
    ]);

    $this->postJson(route('survey.events', [
        'publicKey' => $survey->public_key,
        't' => $token->token,
        'collector' => $collector->slug,
    ]), [
        'event' => 'started',
        'page_key' => 'page_1',
        'metadata' => ['source' => 'email'],
    ])->assertSuccessful();

    $event = SurveyResponseEvent::firstOrFail();

    expect($event->survey_collector_id)->toBe($collector->id)
        ->and($event->event)->toBe('started')
        ->and($event->page_key)->toBe('page_1');

    Event::assertDispatched(SurveyStarted::class, fn (SurveyStarted $event): bool => $event->recipient?->is($recipient) === true);
});
