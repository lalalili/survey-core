<?php

use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Events\SurveyTokenResolved;
use Lalalili\SurveyCore\Listeners\MarkTokenViewed;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

function tokenViewedSetup(): array
{
    $survey = Survey::create(['title' => 'Token Test', 'status' => SurveyStatus::Published]);

    $recipient = SurveyRecipient::create([
        'survey_id' => $survey->id,
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'status' => SurveyRecipientStatus::Active,
    ]);

    $token = SurveyToken::create([
        'survey_id' => $survey->id,
        'survey_recipient_id' => $recipient->id,
        'status' => SurveyTokenStatus::Active,
    ]);

    return [$token, $recipient];
}

it('sets viewed_at when token is first resolved', function () {
    [$token, $recipient] = tokenViewedSetup();

    expect($token->viewed_at)->toBeNull();

    $event = new SurveyTokenResolved($token, $recipient);
    (new MarkTokenViewed())->handle($event);

    $token->refresh();
    expect($token->viewed_at)->not->toBeNull();
});

it('does not overwrite viewed_at on subsequent resolutions', function () {
    [$token, $recipient] = tokenViewedSetup();

    $first = now()->subHours(2);
    $token->update(['viewed_at' => $first]);

    $event = new SurveyTokenResolved($token->fresh(), $recipient);
    (new MarkTokenViewed())->handle($event);

    $token->refresh();
    expect($token->viewed_at->toIso8601String())->toBe($first->toIso8601String());
});

it('dispatches MarkTokenViewed listener when SurveyTokenResolved fires', function () {
    [$token, $recipient] = tokenViewedSetup();

    SurveyTokenResolved::dispatch($token, $recipient);

    $token->refresh();
    expect($token->viewed_at)->not->toBeNull();
});
