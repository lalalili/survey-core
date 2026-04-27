<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Listeners\DispatchSurveySubmittedWebhook;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function webhookSurvey(): Survey
{
    $survey = Survey::create(['title' => 'Webhook Survey', 'status' => SurveyStatus::Published]);

    SurveyField::create([
        'survey_id'   => $survey->id,
        'type'        => SurveyFieldType::ShortText,
        'label'       => 'Name',
        'field_key'   => 'name',
        'is_required' => true,
        'sort_order'  => 1,
    ]);

    return $survey->load('fields');
}

// ── Queuing ───────────────────────────────────────────────────────────────────

it('dispatches the webhook listener when a survey is submitted', function () {
    Queue::fake();

    config()->set('survey-core.webhooks.endpoints', ['https://example.com/hook']);

    $survey = webhookSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice']),
    );

    // Queued listeners are pushed as CallQueuedListener wrapping the real class
    Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, function ($job) {
        return $job->class === DispatchSurveySubmittedWebhook::class;
    });
});

it('does not post when no endpoints are configured', function () {
    Http::fake();

    config()->set('survey-core.webhooks.endpoints', []);

    $event = new SurveySubmitted(
        response:  app(SubmitSurveyResponseAction::class)->execute(
            webhookSurvey(),
            new SubmissionPayload(['name' => 'Bob']),
        ),
        survey:    Survey::first(),
        recipient: null,
    );

    // Invoke listener directly (synchronously)
    $listener = new DispatchSurveySubmittedWebhook();
    $listener->handle($event);

    Http::assertNothingSent();
});

// ── HTTP payload ──────────────────────────────────────────────────────────────

it('sends a JSON payload with survey, response and answers to each endpoint', function () {
    Http::fake(['*' => Http::response('OK', 200)]);

    config()->set('survey-core.webhooks.endpoints', [
        'https://hook1.example.com/webhook',
        'https://hook2.example.com/webhook',
    ]);

    $survey = webhookSurvey();

    // Suppress auto-dispatch so we can call the listener once manually
    \Illuminate\Support\Facades\Event::fake([SurveySubmitted::class]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Carol']),
    );

    $listener = new DispatchSurveySubmittedWebhook();
    $listener->handle(new SurveySubmitted($response, $survey));

    Http::assertSentCount(2);

    Http::assertSent(function ($request) use ($survey, $response) {
        $body = $request->data();

        return $request->url() === 'https://hook1.example.com/webhook'
            && $body['event'] === 'survey.submitted'
            && $body['survey']['id'] === $survey->id
            && $body['response']['id'] === $response->id
            && $body['answers']['name'] === 'Carol';
    });
});

// ── HMAC signature ────────────────────────────────────────────────────────────

it('adds an X-Survey-Signature header when an endpoint has a secret', function () {
    Http::fake(['*' => Http::response('OK', 200)]);

    $secret = 'super-secret';
    config()->set('survey-core.webhooks.endpoints', [
        ['url' => 'https://secure.example.com/hook', 'secret' => $secret],
    ]);

    $survey = webhookSurvey();

    \Illuminate\Support\Facades\Event::fake([SurveySubmitted::class]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Dave']),
    );

    $listener = new DispatchSurveySubmittedWebhook();
    $listener->handle(new SurveySubmitted($response, $survey));

    Http::assertSent(function ($request) use ($secret) {
        $sigHeader = $request->header('X-Survey-Signature')[0] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', json_encode($request->data()), $secret);

        return str_starts_with($sigHeader, 'sha256=')
            && strlen($sigHeader) === strlen($expected);
    });
});

// ── Retry on connection failure ───────────────────────────────────────────────

it('re-throws connection exceptions so the queue retries the job', function () {
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    config()->set('survey-core.webhooks.endpoints', ['https://flaky.example.com/hook']);

    $survey = webhookSurvey();

    \Illuminate\Support\Facades\Event::fake([SurveySubmitted::class]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Eve']),
    );

    $listener = new DispatchSurveySubmittedWebhook();
    $listener->handle(new SurveySubmitted($response, $survey));
})->throws(\Illuminate\Http\Client\ConnectionException::class);
