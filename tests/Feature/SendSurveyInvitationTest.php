<?php

use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\SendSurveyInvitationAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Events\SurveyInvitationDispatched;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

beforeEach(function () {
    $this->survey = Survey::create([
        'title' => 'Invitation Test Survey',
        'status' => SurveyStatus::Published,
    ]);

    $this->recipient = SurveyRecipient::create([
        'survey_id' => $this->survey->id,
        'name' => 'Alice',
        'email' => 'alice@example.com',
    ]);

    $this->action = app(SendSurveyInvitationAction::class);
});

it('dispatches SurveyInvitationDispatched and creates a token for a recipient without one', function () {
    Event::fake([SurveyInvitationDispatched::class]);

    $token = $this->action->execute($this->recipient);

    expect($token)->toBeInstanceOf(SurveyToken::class)
        ->and($token->status)->toBe(SurveyTokenStatus::Active)
        ->and($token->survey_recipient_id)->toBe($this->recipient->id);

    Event::assertDispatched(SurveyInvitationDispatched::class, function (SurveyInvitationDispatched $event) use ($token) {
        return $event->recipient->id === $this->recipient->id
            && $event->token->id === $token->id
            && str_contains($event->surveyUrl, '?t='.$token->token);
    });
});

it('reuses an existing active token instead of creating a new one', function () {
    Event::fake([SurveyInvitationDispatched::class]);

    $existingToken = SurveyToken::create([
        'survey_id' => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status' => SurveyTokenStatus::Active,
    ]);

    $token = $this->action->execute($this->recipient);

    expect($token->id)->toBe($existingToken->id);
    expect(SurveyToken::where('survey_recipient_id', $this->recipient->id)->count())->toBe(1);
});

it('deactivates old tokens and issues a new one on resend', function () {
    Event::fake([SurveyInvitationDispatched::class]);

    $oldToken = SurveyToken::create([
        'survey_id' => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status' => SurveyTokenStatus::Active,
    ]);

    $newToken = $this->action->execute($this->recipient, resend: true);

    expect($newToken->id)->not->toBe($oldToken->id);
    expect($oldToken->fresh()->status)->toBe(SurveyTokenStatus::Inactive);
    expect($newToken->status)->toBe(SurveyTokenStatus::Active);

    Event::assertDispatched(SurveyInvitationDispatched::class);
});

it('embeds the token in the survey URL inside the dispatched event', function () {
    Event::fake([SurveyInvitationDispatched::class]);

    $token = $this->action->execute($this->recipient);

    Event::assertDispatched(SurveyInvitationDispatched::class, function (SurveyInvitationDispatched $event) use ($token) {
        return str_contains($event->surveyUrl, '?t='.$token->token);
    });
});
