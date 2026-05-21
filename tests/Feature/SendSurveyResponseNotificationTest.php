<?php

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Lalalili\EmailCampaign\Actions\SendTransactionalEmailAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Listeners\SendSurveyResponseNotification;
use Lalalili\SurveyCore\Mail\SurveyResponseReceivedMail;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function notificationSurvey(?array $notifyEmails = null): Survey
{
    $settings = $notifyEmails !== null
        ? ['notifications' => ['notify_emails' => $notifyEmails]]
        : [];

    $survey = Survey::create([
        'title'         => 'Notification Survey',
        'status'        => SurveyStatus::Published,
        'settings_json' => $settings ?: null,
    ]);

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

function submitAndGetEvent(Survey $survey): SurveySubmitted
{
    Event::fake([SurveySubmitted::class]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Tester']),
    );

    return new SurveySubmitted($response, $survey);
}

// ── Queue dispatching ─────────────────────────────────────────────────────────

it('queues the notification listener when a survey is submitted', function () {
    Queue::fake();

    config()->set('survey-core.notifications.new_response_notify_emails', ['admin@example.com']);

    notificationSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        Survey::first()->load('fields'),
        new SubmissionPayload(['name' => 'Alice']),
    );

    Queue::assertPushed(CallQueuedListener::class, function ($job) {
        return $job->class === SendSurveyResponseNotification::class;
    });
});

// ── Global config recipients ──────────────────────────────────────────────────

it('sends notification to global config recipients', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', [
        'admin@example.com',
        'manager@example.com',
    ]);

    $survey = notificationSurvey();
    $event = submitAndGetEvent($survey);

    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to) => in_array('admin@example.com', $to) && in_array('manager@example.com', $to) && count($to) === 2);

    (new SendSurveyResponseNotification())->handle($event);
});

// ── Per-survey recipients ─────────────────────────────────────────────────────

it('sends notification to per-survey notify_emails', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', []);

    $survey = notificationSurvey(['owner@survey.com']);
    $event = submitAndGetEvent($survey);

    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to) => $to === ['owner@survey.com']);

    (new SendSurveyResponseNotification())->handle($event);
});

// ── Merged & deduped ─────────────────────────────────────────────────────────

it('merges global and per-survey emails and deduplicates them', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', [
        'shared@example.com',
        'global@example.com',
    ]);

    $survey = notificationSurvey(['shared@example.com', 'per-survey@example.com']);
    $event = submitAndGetEvent($survey);

    // shared@example.com appears in both but must only be sent once → 3 unique
    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to) => count($to) === 3 && in_array('shared@example.com', $to));

    (new SendSurveyResponseNotification())->handle($event);
});

// ── No recipients ─────────────────────────────────────────────────────────────

it('sends nothing when no recipients are configured', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);
    $mock->shouldNotReceive('executeWithMailable');

    config()->set('survey-core.notifications.new_response_notify_emails', []);

    $survey = notificationSurvey();
    $event = submitAndGetEvent($survey);

    (new SendSurveyResponseNotification())->handle($event);
});

// ── Builder UI comma-separated string format ──────────────────────────────────

it('sends notification to per-survey notify_emails from builder UI comma-separated string', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', []);

    $survey = Survey::create([
        'title'         => 'Builder UI Survey',
        'status'        => SurveyStatus::Published,
        'settings_json' => ['notify_emails' => 'builder@example.com, second@example.com'],
    ]);
    SurveyField::create([
        'survey_id' => $survey->id, 'type' => SurveyFieldType::ShortText,
        'label' => 'Name', 'field_key' => 'name', 'is_required' => true, 'sort_order' => 1,
    ]);
    $survey->load('fields');

    $event = submitAndGetEvent($survey);

    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to) => in_array('builder@example.com', $to) && in_array('second@example.com', $to) && count($to) === 2);

    (new SendSurveyResponseNotification())->handle($event);
});

it('ignores invalid email addresses in builder UI notify_emails string', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', []);

    $survey = Survey::create([
        'title'         => 'Invalid Email Survey',
        'status'        => SurveyStatus::Published,
        'settings_json' => ['notify_emails' => 'valid@example.com, not-an-email, another@example.com'],
    ]);
    SurveyField::create([
        'survey_id' => $survey->id, 'type' => SurveyFieldType::ShortText,
        'label' => 'Name', 'field_key' => 'name', 'is_required' => true, 'sort_order' => 1,
    ]);
    $survey->load('fields');

    $event = submitAndGetEvent($survey);

    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to) => count($to) === 2 && in_array('valid@example.com', $to) && in_array('another@example.com', $to));

    (new SendSurveyResponseNotification())->handle($event);
});

// ── Subject line ──────────────────────────────────────────────────────────────

it('passes correct subject to transactional action', function () {
    $mock = Mockery::mock(SendTransactionalEmailAction::class);
    app()->instance(SendTransactionalEmailAction::class, $mock);

    config()->set('survey-core.notifications.new_response_notify_emails', ['notify@example.com']);

    $survey = notificationSurvey();
    $event = submitAndGetEvent($survey);

    $mock->shouldReceive('executeWithMailable')
        ->once()
        ->withArgs(fn ($to, $mailable) => str_contains($mailable->envelope()->subject, $survey->title));

    (new SendSurveyResponseNotification())->handle($event);
});
