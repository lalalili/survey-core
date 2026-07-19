<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveySchemaVersion;
use Lalalili\SurveyCore\Support\SurveyReportCacheRevision;

function versionedSurveySchema(string $label = '姓名'): array
{
    return [
        'id' => 'versioned-survey',
        'title' => '版本問卷',
        'status' => 'draft',
        'version' => 1,
        'pages' => [[
            'id' => 'page_1',
            'title' => '問題',
            'elements' => [[
                'id' => 'question_1',
                'type' => 'short_text',
                'field_key' => 'name',
                'label' => $label,
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'options' => [],
                'settings' => ['custom' => 'value'],
            ]],
        ]],
    ];
}

it('creates an immutable field snapshot and advances the published version pointer', function () {
    $survey = Survey::create([
        'title' => '版本問卷',
        'status' => SurveyStatus::Draft,
        'draft_schema' => versionedSurveySchema(),
    ]);
    $initialRevision = app(SurveyReportCacheRevision::class)->current();

    $published = app(PublishSurveyAction::class)->execute($survey);
    $firstVersion = $published->publishedSchemaVersion;

    expect($firstVersion)->not->toBeNull()
        ->and($firstVersion->version)->toBe(1)
        ->and($firstVersion->schema_json['pages'][0]['elements'][0]['label'])->toBe('姓名')
        ->and($firstVersion->fieldVersions)->toHaveCount(1)
        ->and($firstVersion->fieldVersions->first()->field_key)->toBe('name')
        ->and($firstVersion->fieldVersions->first()->label)->toBe('姓名')
        ->and(app(SurveyReportCacheRevision::class)->current())->toBe($initialRevision + 1);

    $published->update(['draft_schema' => versionedSurveySchema('聯絡人')]);
    $republished = app(PublishSurveyAction::class)->execute($published->refresh());

    expect($republished->published_schema_version_id)->not->toBe($firstVersion->id)
        ->and($republished->version)->toBe(2)
        ->and(SurveySchemaVersion::query()->where('survey_id', $survey->id)->count())->toBe(2)
        ->and($firstVersion->fresh()->fieldVersions->first()->label)->toBe('姓名')
        ->and($republished->publishedSchemaVersion->fieldVersions->first()->label)->toBe('聯絡人')
        ->and(app(SurveyReportCacheRevision::class)->current())->toBe($initialRevision + 2);
});

it('does not create another version when publishing an unchanged published schema', function () {
    $survey = Survey::create([
        'title' => '版本問卷',
        'status' => SurveyStatus::Draft,
        'draft_schema' => versionedSurveySchema(),
    ]);

    $published = app(PublishSurveyAction::class)->execute($survey);
    $publishedAgain = app(PublishSurveyAction::class)->execute($published);

    expect($publishedAgain->published_schema_version_id)->toBe($published->published_schema_version_id)
        ->and($publishedAgain->schemaVersions)->toHaveCount(1);
});
