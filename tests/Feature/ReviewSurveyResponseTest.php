<?php

use Illuminate\Support\Facades\Cache;
use Lalalili\SurveyCore\Actions\ReviewSurveyResponseAction;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\SurveyReportCacheRevision;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Cache::forget(SurveyReportCacheRevision::KEY);
});

it('limits reportable responses to submitted accepted production responses', function () {
    $survey = Survey::create(['title' => 'Reportable', 'status' => SurveyStatus::Published]);

    $reportable = SurveyResponse::create([
        'survey_id' => $survey->id,
        'response_number' => 'R-1',
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Accepted,
        'is_test' => false,
    ]);
    SurveyResponse::create([
        'survey_id' => $survey->id,
        'response_number' => 'R-2',
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Accepted,
        'is_test' => true,
    ]);
    SurveyResponse::create([
        'survey_id' => $survey->id,
        'response_number' => 'R-3',
        'submitted_at' => null,
        'quality_status' => SurveyResponseQualityStatus::Accepted,
        'is_test' => false,
    ]);
    SurveyResponse::create([
        'survey_id' => $survey->id,
        'response_number' => 'R-4',
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Flagged,
        'is_test' => false,
    ]);

    expect(SurveyResponse::query()->reportable()->pluck('id')->all())
        ->toBe([$reportable->id]);
});

it('logs manual status and notes changes and bumps the report revision', function () {
    $survey = Survey::create(['title' => 'Review', 'status' => SurveyStatus::Published]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'response_number' => 'R-100',
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Accepted,
        'notes' => '原備註',
    ]);

    app(ReviewSurveyResponseAction::class)->execute(
        $response,
        SurveyResponseQualityStatus::Quarantined,
        '人工隔離',
        'manual',
    );

    $activity = Activity::query()->inLog('survey_response_review')->sole();

    expect($response->fresh()->quality_status)->toBe(SurveyResponseQualityStatus::Quarantined)
        ->and($response->fresh()->notes)->toBe('人工隔離')
        ->and(app(SurveyReportCacheRevision::class)->current())->toBe(1)
        ->and($activity->log_name)->toBe('survey_response_review')
        ->and($activity->event)->toBe('review_updated')
        ->and($activity->subject->is($response))->toBeTrue()
        ->and($activity->properties->get('source'))->toBe('manual')
        ->and($activity->properties->get('survey_id'))->toBe($survey->id)
        ->and($activity->properties->get('response_number'))->toBe('R-100')
        ->and($activity->properties->get('old'))->toBe([
            'quality_status' => 'accepted',
            'notes' => '原備註',
        ])
        ->and($activity->properties->get('attributes'))->toBe([
            'quality_status' => 'quarantined',
            'notes' => '人工隔離',
        ]);
});

it('logs notes-only changes without bumping the report revision', function () {
    $survey = Survey::create(['title' => 'Notes', 'status' => SurveyStatus::Published]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Accepted,
    ]);

    app(ReviewSurveyResponseAction::class)->execute(
        $response,
        SurveyResponseQualityStatus::Accepted,
        '補充備註',
        'manual',
    );

    expect(Activity::query()->inLog('survey_response_review')->count())->toBe(1)
        ->and(app(SurveyReportCacheRevision::class)->current())->toBe(0);
});

it('does not log or bump the report revision for a no-op review', function () {
    $survey = Survey::create(['title' => 'No-op', 'status' => SurveyStatus::Published]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'quality_status' => SurveyResponseQualityStatus::Accepted,
        'notes' => '相同備註',
    ]);

    app(ReviewSurveyResponseAction::class)->execute(
        $response,
        SurveyResponseQualityStatus::Accepted,
        '相同備註',
        'manual',
    );

    expect(Activity::query()->inLog('survey_response_review')->count())->toBe(0)
        ->and(app(SurveyReportCacheRevision::class)->current())->toBe(0);
});
