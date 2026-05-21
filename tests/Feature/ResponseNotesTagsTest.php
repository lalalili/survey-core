<?php

use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTag;

require __DIR__.'/Phase3TestSupport.php';

it('stores response notes', function () {
    $survey = Survey::create(['title' => 'Notes', 'status' => SurveyStatus::Published]);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);

    $response->update(['notes' => '需要追蹤']);

    expect($response->fresh()->notes)->toBe('需要追蹤');
});

it('isolates tags by survey id', function () {
    $surveyA = Survey::create(['title' => 'A', 'status' => SurveyStatus::Published]);
    $surveyB = Survey::create(['title' => 'B', 'status' => SurveyStatus::Published]);
    SurveyTag::create(['survey_id' => $surveyA->id, 'name' => 'VIP']);
    SurveyTag::create(['survey_id' => $surveyB->id, 'name' => 'VIP']);

    expect($surveyA->tags()->count())->toBe(1)
        ->and($surveyB->tags()->count())->toBe(1)
        ->and($surveyA->tags()->first()->survey_id)->toBe($surveyA->id);
});

it('filters responses by tag relationship', function () {
    $survey = Survey::create(['title' => 'Filter', 'status' => SurveyStatus::Published]);
    $tag = SurveyTag::create(['survey_id' => $survey->id, 'name' => '追蹤']);
    $tagged = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $untagged = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $tagged->tags()->attach($tag);

    $ids = SurveyResponse::query()
        ->whereHas('tags', fn ($query) => $query->whereKey($tag->id))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$tagged->id])
        ->and($ids)->not->toContain($untagged->id);
});

it('cascade deletes pivot rows when a tag is deleted', function () {
    $survey = Survey::create(['title' => 'Cascade', 'status' => SurveyStatus::Published]);
    $tag = SurveyTag::create(['survey_id' => $survey->id, 'name' => '刪除']);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $response->tags()->attach($tag);

    $tag->delete();

    expect($response->tags()->count())->toBe(0);
});
