<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTag;
use Lalalili\SurveyCore\Models\SurveyTriggerActionPreset;
use Lalalili\SurveyCore\Models\SurveyTriggerAllowedHost;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;
use Spatie\Activitylog\Models\Activity;

function makeLoggableTestSurvey(): Survey
{
    return Survey::create(['title' => '紀錄測試問卷', 'status' => SurveyStatus::Draft]);
}

it('logs created/updated/deleted for Survey', function (): void {
    Activity::query()->delete();

    $survey = makeLoggableTestSurvey();
    expect(Activity::query()->where('event', 'created')->where('subject_type', Survey::class)->count())->toBe(1);

    $survey->update(['title' => '改標題']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', Survey::class)->count())->toBe(1);

    $survey->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', Survey::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyCollector', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $collector = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'web_link',
        'name' => '收集器',
        'slug' => 'collector-'.uniqid(),
    ]);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyCollector::class)->count())->toBe(1);

    $collector->update(['name' => '改名稱']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyCollector::class)->count())->toBe(1);

    $collector->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyCollector::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyField', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '題目',
        'field_key' => 'q1',
        'sort_order' => 1,
    ]);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyField::class)->count())->toBe(1);

    $field->update(['label' => '改題目']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyField::class)->count())->toBe(1);

    $field->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyField::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyRecipient', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $recipient = SurveyRecipient::create([
        'survey_id' => $survey->id,
        'name' => '收件人',
        'email' => 'r@example.test',
    ]);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyRecipient::class)->count())->toBe(1);

    $recipient->update(['name' => '改名稱']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyRecipient::class)->count())->toBe(1);

    $recipient->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyRecipient::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyTag', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $tag = SurveyTag::create(['survey_id' => $survey->id, 'name' => '標籤']);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyTag::class)->count())->toBe(1);

    $tag->update(['name' => '改標籤']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyTag::class)->count())->toBe(1);

    $tag->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyTag::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyTriggerRule', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => '規則',
        'is_active' => true,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [],
    ]);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyTriggerRule::class)->count())->toBe(1);

    $rule->update(['name' => '改規則']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyTriggerRule::class)->count())->toBe(1);

    $rule->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyTriggerRule::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyTriggerAllowedHost', function (): void {
    Activity::query()->delete();

    $host = SurveyTriggerAllowedHost::create(['host' => 'hooks.example.test']);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyTriggerAllowedHost::class)->count())->toBe(1);

    $host->update(['description' => '說明']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyTriggerAllowedHost::class)->count())->toBe(1);

    $host->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyTriggerAllowedHost::class)->count())->toBe(1);
});

it('logs created/updated/deleted for SurveyTriggerActionPreset', function (): void {
    Activity::query()->delete();

    $preset = SurveyTriggerActionPreset::create([
        'key' => 'preset-'.uniqid(),
        'name' => '預設動作',
        'action_json' => ['type' => 'http_post'],
        'is_active' => true,
    ]);
    expect(Activity::query()->where('event', 'created')->where('subject_type', SurveyTriggerActionPreset::class)->count())->toBe(1);

    $preset->update(['name' => '改預設']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyTriggerActionPreset::class)->count())->toBe(1);

    $preset->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyTriggerActionPreset::class)->count())->toBe(1);
});

it('only logs the deleted event for SurveyResponse, not created or updated', function (): void {
    $survey = makeLoggableTestSurvey();
    Activity::query()->delete();

    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'is_test' => false,
    ]);
    expect(Activity::query()->where('subject_type', SurveyResponse::class)->count())->toBe(0);

    $response->update(['notes' => '審查備註']);
    expect(Activity::query()->where('event', 'updated')->where('subject_type', SurveyResponse::class)->count())->toBe(0);

    $response->delete();
    expect(Activity::query()->where('event', 'deleted')->where('subject_type', SurveyResponse::class)->count())->toBe(1);
});
