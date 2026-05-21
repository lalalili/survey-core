<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyToken;

require __DIR__.'/Phase3TestSupport.php';

function phase3UniqueSurvey(SurveyUniquenessMode $mode): Survey
{
    $survey = Survey::create([
        'title' => 'Unique',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'uniqueness_mode' => $mode,
        'uniqueness_message' => '請勿重複填寫',
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'name',
        'is_required' => false,
        'sort_order' => 1,
    ]);

    return $survey;
}

it('allows repeated submissions from the same ip when mode is none', function () {
    $survey = phase3UniqueSurvey(SurveyUniquenessMode::None);
    SurveyResponse::create(['survey_id' => $survey->id, 'ip' => '127.0.0.1', 'submitted_at' => now(), 'completion_status' => 'complete']);

    $this->postJson("/survey/{$survey->public_key}/submit", ['answers' => ['name' => 'A']])
        ->assertCreated();
});

it('blocks duplicate ip submissions', function () {
    $survey = phase3UniqueSurvey(SurveyUniquenessMode::Ip);
    SurveyResponse::create(['survey_id' => $survey->id, 'ip' => '127.0.0.1', 'submitted_at' => now(), 'completion_status' => 'complete']);

    $this->postJson("/survey/{$survey->public_key}/submit", ['answers' => ['name' => 'A']])
        ->assertForbidden()
        ->assertJsonPath('message', '請勿重複填寫');
});

it('blocks duplicate recipient email submissions', function () {
    $survey = phase3UniqueSurvey(SurveyUniquenessMode::Email);
    $recipient = SurveyRecipient::create([
        'survey_id' => $survey->id,
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'status' => SurveyRecipientStatus::Active,
    ]);
    $token = SurveyToken::create([
        'survey_id' => $survey->id,
        'survey_recipient_id' => $recipient->id,
        'status' => SurveyTokenStatus::Active,
    ]);
    SurveyResponse::create([
        'survey_id' => $survey->id,
        'survey_recipient_id' => $recipient->id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);

    $this->postJson("/survey/{$survey->public_key}/submit?t={$token->token}", ['answers' => ['name' => 'A']])
        ->assertForbidden();
});

it('sets and honors duplicate cookies', function () {
    $survey = phase3UniqueSurvey(SurveyUniquenessMode::Cookie);

    $this->postJson("/survey/{$survey->public_key}/submit", ['answers' => ['name' => 'A']])
        ->assertCreated()
        ->assertCookie('survey_dup_'.$survey->public_key);

    $this->withCookie('survey_dup_'.$survey->public_key, '1')
        ->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('請勿重複填寫');
});

it('shows uniqueness message on public duplicate page', function () {
    $survey = phase3UniqueSurvey(SurveyUniquenessMode::Ip);
    SurveyResponse::create(['survey_id' => $survey->id, 'ip' => '127.0.0.1', 'submitted_at' => now(), 'completion_status' => 'complete']);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('請勿重複填寫');
});
