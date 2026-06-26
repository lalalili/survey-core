<?php

use Illuminate\Support\Facades\Queue;
use Lalalili\SurveyCore\Actions\Triggers\RunTriggerRuleBatchAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Enums\TriggerRunStatus;
use Lalalili\SurveyCore\Enums\TriggerRunType;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

function makeTriggerSurvey(): Survey
{
    return Survey::create([
        'title' => 'Trigger Survey',
        'status' => SurveyStatus::Published,
    ]);
}

function makeAlwaysMatchRule(Survey $survey, int $windowDays = 7): SurveyTriggerRule
{
    return SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => '測試規則',
        'is_active' => true,
        'schedule_enabled' => true,
        'schedule_window_days' => $windowDays,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [],
    ]);
}

function makeResponse(Survey $survey, array $attributes = []): SurveyResponse
{
    return SurveyResponse::create(array_merge([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'is_test' => false,
    ], $attributes));
}

it('排程掃描近 N 天內已提交填答並派送，留下完成的執行紀錄', function (): void {
    Queue::fake();

    $survey = makeTriggerSurvey();
    $rule = makeAlwaysMatchRule($survey);

    $recent = makeResponse($survey, ['submitted_at' => now()->subDays(2)]);
    makeResponse($survey, ['submitted_at' => now()->subDays(2)]);

    // 視窗外（10 天前）不應掃描
    makeResponse($survey, ['submitted_at' => now()->subDays(10)]);

    // 測試填答不掃描
    makeResponse($survey, ['submitted_at' => now()->subDay(), 'is_test' => true]);

    $run = app(RunTriggerRuleBatchAction::class)->execute($rule, TriggerRunType::Scheduled);

    expect($run->status)->toBe(TriggerRunStatus::Completed)
        ->and($run->trigger_type)->toBe(TriggerRunType::Scheduled)
        ->and($run->scanned_count)->toBe(2)
        ->and($run->matched_count)->toBe(2)
        ->and($run->dispatched_count)->toBe(2)
        ->and($run->finished_at)->not->toBeNull();

    Queue::assertPushed(RunSurveyTriggerJob::class, 2);
    Queue::assertPushed(RunSurveyTriggerJob::class, fn (RunSurveyTriggerJob $job): bool => $job->surveyResponseId === $recent->id);
});

it('排程掃描排除此規則已派送過的填答（冪等）', function (): void {
    Queue::fake();

    $survey = makeTriggerSurvey();
    $rule = makeAlwaysMatchRule($survey);

    $already = makeResponse($survey, ['submitted_at' => now()->subDay()]);
    makeResponse($survey, ['submitted_at' => now()->subDay()]);

    SurveyTriggerDispatch::create([
        'survey_trigger_rule_id' => $rule->id,
        'survey_response_id' => $already->id,
        'status' => TriggerDispatchStatus::Sent,
    ]);

    $run = app(RunTriggerRuleBatchAction::class)->execute($rule, TriggerRunType::Scheduled);

    expect($run->scanned_count)->toBe(1)
        ->and($run->dispatched_count)->toBe(1);

    Queue::assertPushed(RunSurveyTriggerJob::class, 1);
    Queue::assertNotPushed(RunSurveyTriggerJob::class, fn (RunSurveyTriggerJob $job): bool => $job->surveyResponseId === $already->id);
});

it('手動單筆只針對指定填答執行', function (): void {
    Queue::fake();

    $survey = makeTriggerSurvey();
    $rule = makeAlwaysMatchRule($survey);

    $target = makeResponse($survey, ['submitted_at' => now()->subDays(30)]); // 視窗外，但手動指定仍執行
    makeResponse($survey, ['submitted_at' => now()]);

    $run = app(RunTriggerRuleBatchAction::class)->execute($rule, TriggerRunType::Manual, $target->id);

    expect($run->trigger_type)->toBe(TriggerRunType::Manual)
        ->and($run->scanned_count)->toBe(1)
        ->and($run->matched_count)->toBe(1)
        ->and($run->dispatched_count)->toBe(1);

    Queue::assertPushed(RunSurveyTriggerJob::class, 1);
    Queue::assertPushed(RunSurveyTriggerJob::class, fn (RunSurveyTriggerJob $job): bool => $job->surveyResponseId === $target->id);
});
