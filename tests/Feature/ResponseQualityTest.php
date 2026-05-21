<?php

use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

require __DIR__.'/Phase3TestSupport.php';

function phase3QualitySurvey(): Survey
{
    $survey = Survey::create(['title' => 'Quality', 'status' => SurveyStatus::Published]);

    foreach (['first', 'second'] as $index => $fieldKey) {
        SurveyField::create([
            'survey_id' => $survey->id,
            'type' => SurveyFieldType::SingleChoice,
            'label' => $fieldKey,
            'field_key' => $fieldKey,
            'is_required' => false,
            'options_json' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']],
            'sort_order' => $index + 1,
        ]);
    }

    return $survey->load('fields');
}

it('flags too fast submissions', function () {
    $response = app(SubmitSurveyResponseAction::class)->execute(
        phase3QualitySurvey(),
        new SubmissionPayload(['first' => 'a', 'second' => 'b']),
        qualityContext: ['elapsed_ms' => 100],
    );

    expect($response->fresh()->quality_status)->toBe(SurveyResponseQualityStatus::Flagged)
        ->and($response->fresh()->quality_flags_json)->toContain('too_fast');
});

it('quarantines honeypot hits', function () {
    $response = app(SubmitSurveyResponseAction::class)->execute(
        phase3QualitySurvey(),
        new SubmissionPayload(['first' => 'a', 'second' => 'b']),
        qualityContext: ['honeypot_hit' => true],
    );

    expect($response->fresh()->quality_status)->toBe(SurveyResponseQualityStatus::Quarantined);
});

it('flags all same choice answers', function () {
    $response = app(SubmitSurveyResponseAction::class)->execute(
        phase3QualitySurvey(),
        new SubmissionPayload(['first' => 'a', 'second' => 'a']),
        qualityContext: ['elapsed_ms' => 5000],
    );

    expect($response->fresh()->quality_status)->toBe(SurveyResponseQualityStatus::Flagged)
        ->and($response->fresh()->quality_flags_json)->toContain('all_same_answer');
});

it('accepts normal submissions', function () {
    $response = app(SubmitSurveyResponseAction::class)->execute(
        phase3QualitySurvey(),
        new SubmissionPayload(['first' => 'a', 'second' => 'b']),
        qualityContext: ['elapsed_ms' => 5000],
    );

    expect($response->fresh()->quality_status)->toBe(SurveyResponseQualityStatus::Accepted)
        ->and($response->fresh()->quality_flags_json)->toBeNull();
});

it('flags blacklisted ips', function () {
    config()->set('survey-core.security.ip_blacklist', ['10.0.0.1']);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        phase3QualitySurvey(),
        new SubmissionPayload(['first' => 'a', 'second' => 'b']),
        ip: '10.0.0.1',
        qualityContext: ['elapsed_ms' => 5000],
    );

    expect($response->fresh()->quality_flags_json)->toContain('ip_blacklisted');
});
