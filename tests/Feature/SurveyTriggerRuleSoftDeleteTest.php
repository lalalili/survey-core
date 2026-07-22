<?php

use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\TriggerRunStatus;
use Lalalili\SurveyCore\Enums\TriggerRunType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;
use Lalalili\SurveyCore\Models\SurveyTriggerRuleRun;

it('only soft deletes a trigger rule after both the rule and schedule are disabled', function (): void {
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => '啟用中規則',
        'is_active' => true,
        'schedule_enabled' => false,
        'rule_tree_json' => [],
        'actions_json' => [],
    ]);

    expect(fn () => $rule->delete())
        ->toThrow(LogicException::class, '請先停用規則與排程');

    $rule->update(['is_active' => false, 'schedule_enabled' => true]);

    expect(fn () => $rule->delete())
        ->toThrow(LogicException::class, '請先停用規則與排程');

    $rule->update(['schedule_enabled' => false]);
    $rule->delete();

    expect($rule->trashed())->toBeTrue()
        ->and(SurveyTriggerRule::find($rule->id))->toBeNull()
        ->and(SurveyTriggerRule::withTrashed()->find($rule->id))->not->toBeNull();
});

it('preserves rule history on soft delete and only removes it on force delete', function (): void {
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $response = SurveyResponse::create(['survey_id' => $survey->id]);
    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => '停用規則',
        'is_active' => false,
        'schedule_enabled' => false,
        'rule_tree_json' => [],
        'actions_json' => [],
    ]);
    $dispatch = SurveyTriggerDispatch::create([
        'survey_trigger_rule_id' => $rule->id,
        'survey_response_id' => $response->id,
    ]);
    $run = SurveyTriggerRuleRun::create([
        'survey_trigger_rule_id' => $rule->id,
        'trigger_type' => TriggerRunType::Manual,
        'status' => TriggerRunStatus::Completed,
    ]);

    $rule->delete();

    expect($dispatch->fresh())->not->toBeNull()
        ->and($run->fresh())->not->toBeNull();

    $rule->restore();

    expect($rule->fresh()?->trashed())->toBeFalse();

    $rule->delete();
    $rule->forceDelete();

    expect(SurveyTriggerRule::withTrashed()->find($rule->id))->toBeNull()
        ->and($dispatch->fresh())->toBeNull()
        ->and($run->fresh())->toBeNull();
});
