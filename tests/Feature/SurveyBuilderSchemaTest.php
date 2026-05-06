<?php

use Lalalili\SurveyCore\Actions\BuildSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\ExportSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\ImportSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

$surveyBuilderTestCase = class_exists(Tests\TestCase::class)
    ? Tests\TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($surveyBuilderTestCase === Tests\TestCase::class) {
    uses($surveyBuilderTestCase);
}

beforeEach(function () use ($surveyBuilderTestCase): void {
    if ($surveyBuilderTestCase === Tests\TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }
});

function builderSchema(array $overrides = []): array
{
    return array_replace_recursive([
        'id'      => 1,
        'title'   => 'Customer Survey',
        'status'  => 'draft',
        'version' => 1,
        'pages'   => [
            [
                'id'       => 'page_1',
                'title'    => 'Page 1',
                'elements' => [
                    [
                        'id'          => 'q_1',
                        'type'        => 'single_choice',
                        'field_key'   => 'purchase_status',
                        'label'       => 'Have you purchased?',
                        'description' => '',
                        'required'    => true,
                        'placeholder' => null,
                        'options'     => [
                            ['id' => 'opt_1', 'label' => 'Yes', 'value' => 'yes'],
                            ['id' => 'opt_2', 'label' => 'No', 'value' => 'no'],
                        ],
                        'settings' => [],
                    ],
                ],
            ],
        ],
    ], $overrides);
}

it('builds a draft schema from existing survey fields', function () {
    $survey = Survey::create(['title' => 'Existing', 'status' => SurveyStatus::Draft]);

    SurveyField::create([
        'survey_id'    => $survey->id,
        'type'         => SurveyFieldType::SingleChoice,
        'label'        => 'Color',
        'field_key'    => 'color',
        'is_required'  => true,
        'options_json' => ['red' => 'Red', 'blue' => 'Blue'],
        'sort_order'   => 1,
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey);

    expect($schema['title'])->toBe('Existing')
        ->and($schema['pages'][0]['elements'][0]['field_key'])->toBe('color')
        ->and($schema['pages'][0]['elements'][0]['options'][0])->toMatchArray([
            'label' => 'Red',
            'value' => 'red',
        ]);
});

it('autosaves draft schema without changing the published snapshot', function () {
    $survey = Survey::create([
        'title'            => 'Original',
        'status'           => SurveyStatus::Draft,
        'published_schema' => builderSchema(['title' => 'Published']),
    ]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, builderSchema(['title' => 'Draft title']));

    expect($saved->title)->toBe('Draft title')
        ->and($saved->draft_schema['title'])->toBe('Draft title')
        ->and($saved->published_schema['title'])->toBe('Published');
});

it('exports a survey builder schema as json', function () {
    $survey = Survey::create([
        'title'        => 'Exportable',
        'status'       => SurveyStatus::Draft,
        'draft_schema' => builderSchema(['title' => 'Exportable Draft']),
    ]);

    $export = app(ExportSurveyBuilderSchemaAction::class);
    $json = $export->toJson($survey);

    expect($export->execute($survey)['title'])->toBe('Exportable Draft')
        ->and($export->filename($survey))->toEndWith('.builder.json')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['title'])->toBe('Exportable Draft');
});

it('imports a survey builder schema as a new draft survey', function () {
    $survey = app(ImportSurveyBuilderSchemaAction::class)->execute(
        builderSchema(['title' => 'Imported Survey']),
        title: 'Imported Override',
    );

    expect($survey->title)->toBe('Imported Override')
        ->and($survey->status)->toBe(SurveyStatus::Draft)
        ->and($survey->draft_schema['title'])->toBe('Imported Override')
        ->and($survey->fields()->where('field_key', 'purchase_status')->exists())->toBeTrue();
});

it('can publish an imported survey builder schema', function () {
    $survey = app(ImportSurveyBuilderSchemaAction::class)->fromJson(
        json_encode(builderSchema(['title' => 'Published Import']), JSON_THROW_ON_ERROR),
        publish: true,
    );

    expect($survey->status)->toBe(SurveyStatus::Published)
        ->and($survey->published_schema['title'])->toBe('Published Import')
        ->and($survey->published_at)->not->toBeNull();
});

it('rejects malformed builder schemas', function () {
    $survey = Survey::create(['title' => 'Broken', 'status' => SurveyStatus::Draft]);

    $schema = builderSchema();
    $schema['pages'][0]['elements'][0]['options'] = [];

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
})->throws(SurveyValidationException::class);

it('publishes the draft schema and syncs answer fields', function () {
    $survey = Survey::create(['title' => 'Draft', 'status' => SurveyStatus::Draft, 'version' => 1]);

    SurveyField::create([
        'survey_id'   => $survey->id,
        'type'        => SurveyFieldType::Hidden,
        'label'       => 'Campaign',
        'field_key'   => 'campaign_id',
        'is_hidden'   => true,
        'sort_order'  => 1,
    ]);

    $schema = builderSchema();
    $schema['pages'][0]['elements'] = [
        [
            'id'          => 'intro',
            'type'        => 'section_title',
            'field_key'   => null,
            'label'       => '區段標題',
            'description' => 'Welcome',
            'required'    => false,
            'placeholder' => null,
            'options'     => [],
            'settings'    => [],
        ],
        [
            'id'          => 'q_1',
            'type'        => 'short_text',
            'field_key'   => 'name',
            'label'       => 'Name',
            'description' => '',
            'required'    => true,
            'placeholder' => 'Your name',
            'options'     => [],
            'settings'    => [],
        ],
    ];

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->status)->toBe(SurveyStatus::Published)
        ->and($published->version)->toBe(2)
        ->and($published->published_schema['title'])->toBe('Customer Survey')
        ->and($published->fields()->where('field_key', 'name')->exists())->toBeTrue()
        ->and($published->fields()->where('field_key', 'campaign_id')->exists())->toBeTrue()
        ->and($published->fields()->where('field_key', 'intro')->exists())->toBeTrue();
});

it('republishes a published survey when the draft schema changed', function () {
    $survey = Survey::create([
        'title'            => 'Published',
        'status'           => SurveyStatus::Published,
        'version'          => 2,
        'draft_schema'     => builderSchema(['title' => 'Published']),
        'published_schema' => builderSchema(['title' => 'Published']),
        'published_at'     => now()->subDay(),
    ]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, builderSchema(['title' => 'Republished']));

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->status)->toBe(SurveyStatus::Published)
        ->and($published->version)->toBe(3)
        ->and($published->published_schema['title'])->toBe('Republished');
});

it('does not bump the version when publishing an unchanged published survey', function () {
    $schema = builderSchema(['title' => 'Already Published']);

    $survey = Survey::create([
        'title'            => 'Already Published',
        'status'           => SurveyStatus::Published,
        'version'          => 2,
        'draft_schema'     => $schema,
        'published_schema' => $schema,
        'published_at'     => now(),
    ]);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->status)->toBe(SurveyStatus::Published)
        ->and($published->version)->toBe(2)
        ->and($published->published_schema['title'])->toBe('Already Published');
});
