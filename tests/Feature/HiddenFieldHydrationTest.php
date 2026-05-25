<?php

use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;

beforeEach(function () {
    Event::fake();

    $this->survey = Survey::create([
        'title'  => 'Personalized Survey',
        'status' => SurveyStatus::Published,
    ]);

    // Visible field
    SurveyField::create([
        'survey_id'   => $this->survey->id,
        'type'        => SurveyFieldType::ShortText,
        'label'       => 'Your Feedback',
        'field_key'   => 'feedback',
        'is_required' => true,
        'sort_order'  => 1,
    ]);

    // Hidden personalized field — should be injected from recipient payload
    SurveyField::create([
        'survey_id'        => $this->survey->id,
        'type'             => SurveyFieldType::Hidden,
        'label'            => 'Member Level',
        'field_key'        => 'member_level',
        'is_hidden'        => true,
        'is_personalized'  => true,
        'personalized_key' => 'member_level',
        'sort_order'       => 2,
    ]);

    $this->recipient = SurveyRecipient::create([
        'survey_id'    => $this->survey->id,
        'name'         => 'Bob',
        'email'        => 'bob@example.com',
        'payload_json' => ['member_level' => 'platinum'],
        'status'       => SurveyRecipientStatus::Active,
    ]);

    $this->survey->load('fields');
});

it('injects personalized hidden field value from recipient payload', function () {
    $token = app(GenerateSurveyTokenAction::class)->execute($this->survey, $this->recipient, maxSubmissions: 1);
    $resolved = app(ResolveSurveyTokenAction::class)->execute($this->survey, $token->token);

    $payload = new SubmissionPayload(
        visibleAnswers: ['feedback' => 'Great product!'],
        tokenContext: $resolved,
    );

    $response = app(SubmitSurveyResponseAction::class)->execute($this->survey, $payload);

    $answers = $response->answers->keyBy(fn ($a) => $a->field->field_key);

    expect($answers->get('member_level')->answer_text)->toBe('platinum')
        ->and($answers->get('feedback')->answer_text)->toBe('Great product!');
});

it('discards frontend-forged hidden field values and uses server-resolved value instead', function () {
    $token = app(GenerateSurveyTokenAction::class)->execute($this->survey, $this->recipient, maxSubmissions: 1);
    $resolved = app(ResolveSurveyTokenAction::class)->execute($this->survey, $token->token);

    // Frontend tries to forge the hidden field
    $payload = new SubmissionPayload(
        visibleAnswers: [
            'feedback'     => 'Great!',
            'member_level' => 'FORGED_VALUE',  // should be ignored
        ],
        tokenContext: $resolved,
    );

    $response = app(SubmitSurveyResponseAction::class)->execute($this->survey, $payload);

    $answers = $response->answers->keyBy(fn ($a) => $a->field->field_key);

    // Server-resolved value wins
    expect($answers->get('member_level')->answer_text)->toBe('platinum');
});

it('records token usage after submission', function () {
    $token = app(GenerateSurveyTokenAction::class)->execute($this->survey, $this->recipient, maxSubmissions: 5);
    $resolved = app(ResolveSurveyTokenAction::class)->execute($this->survey, $token->token);

    $payload = new SubmissionPayload(
        visibleAnswers: ['feedback' => 'Good'],
        tokenContext: $resolved,
    );

    app(SubmitSurveyResponseAction::class)->execute($this->survey, $payload);

    $token->refresh();
    expect($token->used_count)->toBe(1)
        ->and($token->last_used_at)->not->toBeNull();
});
