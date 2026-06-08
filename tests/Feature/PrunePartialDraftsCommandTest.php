<?php

use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;

beforeEach(function () {
    $this->survey = Survey::create(['title' => 'Prune Test', 'status' => SurveyStatus::Published]);
});

function makeDraft(Survey $survey, int $ageHours): SurveyResponse
{
    $draft = SurveyResponse::create([
        'survey_id' => $survey->id,
        'completion_status' => SurveyResponseCompletionStatus::Partial,
    ]);

    // 直接回填 created_at 模擬草稿年齡
    $draft->forceFill(['created_at' => now()->subHours($ageHours)])->saveQuietly();

    return $draft->refresh();
}

it('prunes abandoned partial drafts older than the retention window', function () {
    config(['survey-core.uploads.partial_draft_retention_hours' => 24]);

    $old = makeDraft($this->survey, ageHours: 48);
    $recent = makeDraft($this->survey, ageHours: 2);

    $this->artisan('survey:prune-partial-drafts')->assertSuccessful();

    expect(SurveyResponse::find($old->id))->toBeNull()
        ->and(SurveyResponse::find($recent->id))->not->toBeNull();
});

it('never prunes complete responses even when old', function () {
    $complete = SurveyResponse::create([
        'survey_id' => $this->survey->id,
        'completion_status' => SurveyResponseCompletionStatus::Complete,
        'submitted_at' => now()->subDays(10),
    ]);
    $complete->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('survey:prune-partial-drafts', ['--hours' => 0])->assertSuccessful();

    expect(SurveyResponse::find($complete->id))->not->toBeNull();
});

it('respects the --hours override', function () {
    $draft = makeDraft($this->survey, ageHours: 5);

    // 預設 24h 不會刪 5h 前的草稿
    $this->artisan('survey:prune-partial-drafts')->assertSuccessful();
    expect(SurveyResponse::find($draft->id))->not->toBeNull();

    // 以 --hours=1 覆寫，5h 前的草稿應被刪
    $this->artisan('survey:prune-partial-drafts', ['--hours' => 1])->assertSuccessful();
    expect(SurveyResponse::find($draft->id))->toBeNull();
});
