<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveySchemaVersion;

it('backfills legacy schema versions and immutable answer snapshots without changing row counts', function () {
    $schema = [
        'id' => 'legacy_source',
        'title' => '歷史問卷',
        'version' => 3,
        'pages' => [[
            'id' => 'page_1',
            'kind' => 'question',
            'title' => '題目',
            'elements' => [[
                'id' => 'element_satisfaction',
                'field_key' => 'satisfaction',
                'label' => '原始滿意度',
                'type' => 'single_choice',
                'options' => [['value' => 'yes', 'label' => '滿意']],
                'settings' => ['layout' => 'vertical'],
            ]],
        ]],
    ];

    $survey = Survey::create([
        'title' => '歷史問卷',
        'status' => SurveyStatus::Published,
        'version' => 3,
        'published_schema' => $schema,
        'published_at' => now()->subDay(),
    ]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => '目前滿意度',
        'field_key' => 'satisfaction',
        'options_json' => [['value' => 'yes', 'label' => '目前滿意']],
        'settings_json' => ['layout' => 'horizontal'],
        'sort_order' => 1,
    ]);
    $response = SurveyResponse::create(['survey_id' => $survey->id]);
    $answer = SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $field->id,
        'answer_text' => 'yes',
    ]);
    $responseCount = SurveyResponse::count();
    $answerCount = SurveyAnswer::count();

    $migration = require __DIR__.'/../../database/migrations/2026_07_19_035614_backfill_survey_schema_versions.php';
    $migration->up();

    $survey->refresh();
    $response->refresh();
    $answer->refresh();
    $version = SurveySchemaVersion::findOrFail($survey->published_schema_version_id);

    expect(SurveyResponse::count())->toBe($responseCount)
        ->and(SurveyAnswer::count())->toBe($answerCount)
        ->and($response->schema_version_id)->toBe($version->id)
        ->and($answer->snapshot_field_key)->toBe('satisfaction')
        ->and($answer->snapshot_field_label)->toBe('目前滿意度')
        ->and($answer->snapshot_field_type)->toBe('single_choice')
        ->and($answer->snapshot_options_json)->toBe([['value' => 'yes', 'label' => '目前滿意']])
        ->and($version->source)->toBe('legacy_backfill')
        ->and($version->schema_json)->toBe($schema)
        ->and($version->fieldVersions)->toHaveCount(1)
        ->and($version->fieldVersions->first()->element_id)->toBe('element_satisfaction')
        ->and($survey->schemaVersions->first()->is($version))->toBeTrue()
        ->and($response->schemaVersion->is($version))->toBeTrue();
});

it('does not assign a published version to an unpublished draft without responses', function () {
    $survey = Survey::create([
        'title' => '無快照問卷',
        'status' => SurveyStatus::Draft,
        'version' => 1,
    ]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '姓名',
        'field_key' => 'name',
        'sort_order' => 1,
    ]);

    $migration = require __DIR__.'/../../database/migrations/2026_07_19_035614_backfill_survey_schema_versions.php';
    $migration->up();

    expect($survey->refresh()->published_schema_version_id)->toBeNull()
        ->and($survey->schemaVersions()->count())->toBe(0)
        ->and($field->exists)->toBeTrue();
});

it('backfills response history for an unpublished draft without setting its current pointer', function () {
    $survey = Survey::create(['title' => '歷史草稿', 'status' => SurveyStatus::Draft, 'version' => 1]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '姓名',
        'field_key' => 'name',
        'sort_order' => 1,
    ]);
    $response = SurveyResponse::create(['survey_id' => $survey->id]);

    $migration = require __DIR__.'/../../database/migrations/2026_07_19_035614_backfill_survey_schema_versions.php';
    $migration->up();

    expect($survey->refresh()->published_schema_version_id)->toBeNull()
        ->and($survey->schemaVersions()->count())->toBe(1)
        ->and($response->refresh()->schema_version_id)->not->toBeNull();
});
