<?php

use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

require __DIR__.'/Phase3TestSupport.php';

function phase3QuotaSurvey(array $attributes = []): Survey
{
    $survey = Survey::create(array_merge([
        'title' => 'Quota Survey',
        'status' => SurveyStatus::Published,
    ], $attributes));

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'name',
        'is_required' => false,
        'sort_order' => 1,
    ]);

    return $survey->load('fields');
}

it('blocks the fourth submission when max responses is three', function () {
    $survey = phase3QuotaSurvey(['max_responses' => 3]);

    foreach (range(1, 3) as $index) {
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'completion_status' => 'complete',
        ]);
    }

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('問卷已額滿');
});

it('does not limit submissions when max responses is null', function () {
    $survey = phase3QuotaSurvey(['max_responses' => null]);

    SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute($survey->fresh('fields'), new SubmissionPayload(['name' => 'A']));

    expect($response->id)->toBeInt();
});

it('shows the custom quota message', function () {
    $survey = phase3QuotaSurvey(['max_responses' => 0, 'quota_message' => '名額已滿，感謝支持']);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('名額已滿，感謝支持');
});

it('shows closed when survey has ended', function () {
    $survey = phase3QuotaSurvey(['ends_at' => now()->subMinute()]);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('問卷已關閉');
});

it('shows not started when survey starts in the future', function () {
    $survey = phase3QuotaSurvey(['starts_at' => now()->addHour()]);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('問卷尚未開放');
});

it('publishes and closes surveys from the schedule command', function () {
    $draft = phase3QuotaSurvey([
        'title' => 'Draft Scheduled',
        'status' => SurveyStatus::Draft,
        'starts_at' => now()->subMinute(),
    ]);
    $published = phase3QuotaSurvey([
        'title' => 'Published Scheduled',
        'status' => SurveyStatus::Published,
        'ends_at' => now()->subMinute(),
    ]);

    $this->artisan('survey:schedule')->assertSuccessful();

    expect($draft->fresh()->status)->toBe(SurveyStatus::Published)
        ->and($published->fresh()->status)->toBe(SurveyStatus::Closed);
});
