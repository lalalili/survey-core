<?php

use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Exceptions\InvalidSurveyTokenException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

beforeEach(function () {
    $this->action = new ResolveSurveyTokenAction();

    $this->survey = Survey::create([
        'title'  => 'Test Survey',
        'status' => SurveyStatus::Published,
    ]);

    $this->recipient = SurveyRecipient::create([
        'survey_id'    => $this->survey->id,
        'name'         => 'Alice',
        'email'        => 'alice@example.com',
        'payload_json' => ['customer_name' => 'Alice', 'member_level' => 'gold'],
        'status'       => SurveyRecipientStatus::Active,
    ]);
});

it('resolves a valid token and returns recipient payload', function () {
    $token = SurveyToken::create([
        'survey_id'           => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status'              => SurveyTokenStatus::Active,
    ]);

    $result = $this->action->execute($this->survey, $token->token);

    expect($result->token->id)->toBe($token->id)
        ->and($result->recipient->id)->toBe($this->recipient->id)
        ->and($result->payload)->toBe(['customer_name' => 'Alice', 'member_level' => 'gold']);
});

it('throws for a non-existent token', function () {
    $this->action->execute($this->survey, 'does-not-exist');
})->throws(InvalidSurveyTokenException::class, 'Token not found.');

it('throws when token belongs to a different survey', function () {
    $otherSurvey = Survey::create(['title' => 'Other', 'status' => SurveyStatus::Published]);
    $token = SurveyToken::create([
        'survey_id'           => $otherSurvey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status'              => SurveyTokenStatus::Active,
    ]);

    $this->action->execute($this->survey, $token->token);
})->throws(InvalidSurveyTokenException::class, 'does not belong');

it('throws for an inactive token', function () {
    $token = SurveyToken::create([
        'survey_id'           => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status'              => SurveyTokenStatus::Inactive,
    ]);

    $this->action->execute($this->survey, $token->token);
})->throws(InvalidSurveyTokenException::class, 'inactive');

it('throws for an expired token', function () {
    $token = SurveyToken::create([
        'survey_id'           => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status'              => SurveyTokenStatus::Active,
        'expires_at'          => now()->subMinute(),
    ]);

    $this->action->execute($this->survey, $token->token);
})->throws(InvalidSurveyTokenException::class, 'expired');

it('throws when token has reached its max submissions', function () {
    $token = SurveyToken::create([
        'survey_id'           => $this->survey->id,
        'survey_recipient_id' => $this->recipient->id,
        'status'              => SurveyTokenStatus::Active,
        'max_submissions'     => 1,
        'used_count'          => 1,
    ]);

    $this->action->execute($this->survey, $token->token);
})->throws(InvalidSurveyTokenException::class, 'submission limit');
