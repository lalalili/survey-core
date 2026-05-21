<?php

use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\EvaluateResponseQualityAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

beforeEach(function () {
    Event::fake();
});

function qualitySurvey(?array $settingsJson = null): Survey
{
    $survey = Survey::create([
        'title'         => 'Quality Survey',
        'status'        => SurveyStatus::Published,
        'settings_json' => $settingsJson,
    ]);

    SurveyField::create([
        'survey_id'   => $survey->id,
        'type'        => SurveyFieldType::ShortText,
        'label'       => 'Name',
        'field_key'   => 'q_name',
        'is_required' => true,
        'sort_order'  => 1,
    ]);

    return $survey->load('fields');
}

// ── anomaly.min_seconds → per-survey quality flagging ─────────────────────────

it('flags too_fast when elapsed_ms is below per-survey anomaly.min_seconds', function () {
    $survey = qualitySurvey(['anomaly' => ['min_seconds' => 60]]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['q_name' => 'Tester']),
        qualityContext: ['elapsed_ms' => 5000],  // 5s < 60s threshold
    );

    expect($response->quality_status)->toBe(SurveyResponseQualityStatus::Flagged);
    expect($response->quality_flags_json)->toContain('too_fast');
});

it('does not flag too_fast when elapsed_ms meets per-survey anomaly.min_seconds', function () {
    $survey = qualitySurvey(['anomaly' => ['min_seconds' => 30]]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['q_name' => 'Tester']),
        qualityContext: ['elapsed_ms' => 35000],  // 35s > 30s threshold
    );

    expect($response->quality_status)->toBe(SurveyResponseQualityStatus::Accepted);
    expect($response->quality_flags_json)->toBeNull();
});

it('falls back to global config min_submission_ms when anomaly.min_seconds is not set', function () {
    config()->set('survey-core.security.min_submission_ms', 5000);

    $survey = qualitySurvey();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['q_name' => 'Tester']),
        qualityContext: ['elapsed_ms' => 1000],  // 1s < 5s global config
    );

    expect($response->quality_status)->toBe(SurveyResponseQualityStatus::Flagged);
    expect($response->quality_flags_json)->toContain('too_fast');
});

// ── anomaly.detect_duplicate → quality flagging ───────────────────────────────

it('flags anomaly_duplicate when is_anomaly_duplicate is passed in context', function () {
    $response = SurveyResponse::create([
        'survey_id'          => qualitySurvey()->id,
        'completion_status'  => 'complete',
    ]);

    app(EvaluateResponseQualityAction::class)->execute(
        $response->load('answers.field'),
        ['is_anomaly_duplicate' => true],
    );

    $response->refresh();

    expect($response->quality_status)->toBe(SurveyResponseQualityStatus::Flagged);
    expect($response->quality_flags_json)->toContain('anomaly_duplicate');
});

it('does not flag anomaly_duplicate when is_anomaly_duplicate is false', function () {
    $survey = qualitySurvey();
    $response = SurveyResponse::create([
        'survey_id'         => $survey->id,
        'completion_status' => 'complete',
    ]);

    app(EvaluateResponseQualityAction::class)->execute(
        $response->load('answers.field'),
        ['is_anomaly_duplicate' => false],
    );

    $response->refresh();

    expect($response->quality_status)->toBe(SurveyResponseQualityStatus::Accepted);
    expect($response->quality_flags_json)->toBeNull();
});
