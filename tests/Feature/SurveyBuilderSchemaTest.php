<?php

use Lalalili\SurveyCore\Actions\BuildSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\CreateBlankSurveyBuilderSurveyAction;
use Lalalili\SurveyCore\Actions\ExportSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\ImportSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Tests\TestCase;

$surveyBuilderTestCase = class_exists(TestCase::class)
    ? TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($surveyBuilderTestCase === TestCase::class) {
    uses($surveyBuilderTestCase);
}

beforeEach(function () use ($surveyBuilderTestCase): void {
    if ($surveyBuilderTestCase === TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }
});

function builderSchema(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 1,
        'title' => 'Customer Survey',
        'status' => 'draft',
        'version' => 1,
        'pages' => [
            [
                'id' => 'page_1',
                'title' => 'Page 1',
                'elements' => [
                    [
                        'id' => 'q_1',
                        'type' => 'single_choice',
                        'field_key' => 'purchase_status',
                        'label' => 'Have you purchased?',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [
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
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Color',
        'field_key' => 'color',
        'is_required' => true,
        'options_json' => ['red' => 'Red', 'blue' => 'Blue'],
        'sort_order' => 1,
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey);

    expect($schema['title'])->toBe('Existing')
        ->and($schema['pages'][0]['elements'][0]['field_key'])->toBe('color')
        ->and($schema['pages'][0]['elements'][0]['options'][0])->toMatchArray([
            'label' => 'Red',
            'value' => 'red',
        ]);
});

it('converts legacy email and phone fields to short text presets for builder schemas', function () {
    $survey = Survey::create(['title' => 'Legacy presets', 'status' => SurveyStatus::Draft]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Email,
        'label' => 'Email',
        'field_key' => 'email',
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Phone,
        'label' => '手機',
        'field_key' => 'mobile',
        'sort_order' => 2,
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey);
    $elements = collect($schema['pages'][0]['elements'])->keyBy('field_key');

    expect($elements['email']['type'])->toBe('short_text')
        ->and($elements['email']['settings']['input_format'])->toBe('email')
        ->and($elements['mobile']['type'])->toBe('short_text')
        ->and($elements['mobile']['settings']['input_format'])->toBe('mobile_tw')
        ->and($elements['mobile']['settings']['pattern'])->toBe('09[0-9]{8}');
});

it('normalizes legacy email and phone elements in draft schemas for the builder', function () {
    $survey = Survey::create([
        'title' => 'Legacy draft schema',
        'status' => SurveyStatus::Draft,
        'draft_schema' => builderSchema([
            'pages' => [
                [
                    'id' => 'page_1',
                    'title' => 'Page 1',
                    'elements' => [
                        [
                            'id' => 'q_email',
                            'type' => 'email',
                            'field_key' => 'email',
                            'label' => 'Email',
                            'description' => '',
                            'required' => true,
                            'placeholder' => null,
                            'options' => [],
                            'settings' => [],
                        ],
                        [
                            'id' => 'q_phone',
                            'type' => 'phone',
                            'field_key' => 'mobile',
                            'label' => '手機',
                            'description' => '',
                            'required' => true,
                            'placeholder' => null,
                            'options' => [],
                            'settings' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey);
    $elements = collect($schema['pages'][0]['elements'])->keyBy('field_key');

    expect($elements['email']['type'])->toBe('short_text')
        ->and($elements['email']['settings']['input_format'])->toBe('email')
        ->and($elements['mobile']['type'])->toBe('short_text')
        ->and($elements['mobile']['settings']['input_format'])->toBe('mobile_tw')
        ->and($elements['mobile']['settings']['minlength'])->toBe(10)
        ->and($elements['mobile']['settings']['pattern'])->toBe('09[0-9]{8}');
});

it('rejects legacy field types when saving new builder schemas', function (string $type) {
    app(ValidateSurveyBuilderSchemaAction::class)->execute(builderSchema([
        'pages' => [
            [
                'id' => 'page_1',
                'title' => 'Page 1',
                'elements' => [
                    [
                        'id' => 'legacy_field',
                        'type' => $type,
                        'field_key' => 'legacy_field',
                        'label' => 'Legacy field',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [],
                        'settings' => [],
                    ],
                ],
            ],
        ],
    ]));
})->throws(SurveyValidationException::class)->with([
    'email',
    'phone',
    'address',
]);

it('keeps hidden fields visible in the builder when they are missing from draft schema', function () {
    $survey = Survey::create([
        'title' => 'Personalized Survey',
        'status' => SurveyStatus::Draft,
        'draft_schema' => builderSchema([
            'pages' => [
                [
                    'id' => 'page_basic',
                    'kind' => 'question',
                    'title' => 'Basic',
                    'elements' => [
                        [
                            'id' => 'q_visible',
                            'type' => 'short_text',
                            'field_key' => 'visible_name',
                            'label' => 'Name',
                            'description' => '',
                            'required' => true,
                            'placeholder' => null,
                            'options' => [],
                            'settings' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $page = SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'page_basic',
        'title' => 'Basic',
        'kind' => SurveyPageKind::Question,
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Plate number',
        'field_key' => 'plate_number',
        'is_hidden' => true,
        'personalized_key' => 'plate',
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'visible_name',
        'is_hidden' => false,
        'sort_order' => 2,
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey->refresh());
    $fieldKeys = collect($schema['pages'][0]['elements'])->pluck('field_key')->all();

    expect($fieldKeys)->toBe(['plate_number', 'visible_name'])
        ->and($schema['pages'][0]['elements'][0])->toMatchArray([
            'field_key' => 'plate_number',
            'is_hidden' => true,
            'personalized_key' => 'plate',
        ]);
});

it('syncs survey-level settings through the builder schema', function () {
    $survey = Survey::create([
        'title' => 'Settings Survey',
        'status' => SurveyStatus::Draft,
        'description' => 'Original description',
        'starts_at' => '2026-05-10 09:00:00',
        'ends_at' => '2026-05-20 18:00:00',
        'max_responses' => 50,
        'quota_message' => 'Full',
        'uniqueness_mode' => SurveyUniquenessMode::Cookie,
        'uniqueness_message' => 'Already done',
        'settings_json' => ['password' => 'secret'],
        'draft_schema' => builderSchema(),
    ]);

    $schema = app(BuildSurveyBuilderSchemaAction::class)->execute($survey);

    expect($schema['settings'])->toMatchArray([
        'description' => 'Original description',
        'starts_at' => '2026-05-10T09:00',
        'ends_at' => '2026-05-20T18:00',
        'max_responses' => 50,
        'quota_message' => 'Full',
        'uniqueness_mode' => 'cookie',
        'uniqueness_message' => 'Already done',
        'password' => 'secret',
    ]);

    $schema['settings']['description'] = 'Updated description';
    $schema['settings']['starts_at'] = '2026-06-01T08:30';
    $schema['settings']['ends_at'] = '2026-06-30T17:45';
    $schema['settings']['max_responses'] = 120;
    $schema['settings']['quota_message'] = 'Quota reached';
    $schema['settings']['uniqueness_mode'] = 'ip';
    $schema['settings']['uniqueness_message'] = 'Duplicate';
    $schema['settings']['password'] = 'changed';

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey->refresh(), $schema);

    expect($saved->description)->toBe('Updated description')
        ->and($saved->starts_at?->format('Y-m-d H:i'))->toBe('2026-06-01 08:30')
        ->and($saved->ends_at?->format('Y-m-d H:i'))->toBe('2026-06-30 17:45')
        ->and($saved->max_responses)->toBe(120)
        ->and($saved->quota_message)->toBe('Quota reached')
        ->and($saved->uniqueness_mode)->toBe(SurveyUniquenessMode::Ip)
        ->and($saved->uniqueness_message)->toBe('Duplicate')
        ->and($saved->settings_json)->toBe(['password' => 'changed'])
        ->and($saved->draft_schema['settings'])->not->toHaveKey('close_at');
});

it('sanitizes rich html in builder schema before saving and publishing', function (): void {
    config()->set('survey-core.security.sanitize_html', true);

    $survey = Survey::create(['title' => 'Unsafe HTML', 'status' => SurveyStatus::Draft, 'version' => 1]);
    $unsafeHtml = '<p>Hello <strong>safe</strong><script>bad()</script><a href="javascript:alert(1)" onclick="bad()">bad link</a><a href="https://example.com" target="_blank">safe link</a></p>';

    $schema = builderSchema([
        'settings' => [
            'description' => $unsafeHtml,
            'terms_text' => $unsafeHtml,
        ],
    ]);
    $schema['pages'] = [
        [
            'id' => 'welcome',
            'kind' => 'welcome',
            'title' => 'Welcome',
            'welcome_settings' => ['content' => $unsafeHtml],
            'elements' => [],
        ],
        [
            'id' => 'page_1',
            'kind' => 'question',
            'title' => 'Page 1',
            'elements' => [[
                'id' => 'content',
                'type' => 'description_block',
                'field_key' => null,
                'label' => 'Content',
                'description' => $unsafeHtml,
                'required' => false,
                'placeholder' => null,
                'options' => [],
                'settings' => [],
            ]],
        ],
        [
            'id' => 'thanks',
            'kind' => 'thank_you',
            'title' => 'Thanks',
            'thank_you_settings' => ['message' => $unsafeHtml],
            'elements' => [],
        ],
    ];

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    $published = app(PublishSurveyAction::class)->execute($saved->refresh());

    $combined = implode("\n", [
        $published->description,
        $published->settings_json['terms_text'],
        $published->draft_schema['pages'][0]['welcome_settings']['content'],
        $published->published_schema['pages'][1]['elements'][0]['description'],
        $published->pages()->where('page_key', 'thanks')->first()->settings_json['thank_you']['message'],
    ]);

    expect($combined)
        ->toContain('<strong>safe</strong>')
        ->toContain('href="https://example.com"')
        ->toContain('rel="noopener noreferrer"')
        ->not->toContain('<script>')
        ->not->toContain('javascript:')
        ->not->toContain('onclick=');
});

it('creates a blank survey that opens directly in the builder', function () {
    $survey = app(CreateBlankSurveyBuilderSurveyAction::class)->execute();

    expect($survey->title)->toBe('未命名問卷')
        ->and($survey->status)->toBe(SurveyStatus::Draft)
        ->and($survey->public_key)->not->toBeEmpty()
        ->and($survey->draft_schema['pages'][0])->toMatchArray([
            'id' => 'page_1',
            'kind' => 'question',
            'title' => '第 1 頁',
            'elements' => [],
        ]);
});

it('autosaves draft schema without changing the published snapshot', function () {
    $survey = Survey::create([
        'title' => 'Original',
        'status' => SurveyStatus::Draft,
        'published_schema' => builderSchema(['title' => 'Published']),
    ]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, builderSchema(['title' => 'Draft title']));

    expect($saved->title)->toBe('Draft title')
        ->and($saved->draft_schema['title'])->toBe('Draft title')
        ->and($saved->published_schema['title'])->toBe('Published');
});

it('exports a survey builder schema as json', function () {
    $survey = Survey::create([
        'title' => 'Exportable',
        'status' => SurveyStatus::Draft,
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
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Hidden,
        'label' => 'Campaign',
        'field_key' => 'campaign_id',
        'is_hidden' => true,
        'sort_order' => 1,
    ]);

    $schema = builderSchema();
    $schema['pages'][0]['elements'] = [
        [
            'id' => 'intro',
            'type' => 'section_title',
            'field_key' => null,
            'label' => '區段標題',
            'description' => 'Welcome',
            'required' => false,
            'placeholder' => null,
            'options' => [],
            'settings' => [],
        ],
        [
            'id' => 'q_1',
            'type' => 'short_text',
            'field_key' => 'name',
            'label' => 'Name',
            'description' => '',
            'required' => true,
            'placeholder' => 'Your name',
            'options' => [],
            'settings' => [],
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
        'title' => 'Published',
        'status' => SurveyStatus::Published,
        'version' => 2,
        'draft_schema' => builderSchema(['title' => 'Published']),
        'published_schema' => builderSchema(['title' => 'Published']),
        'published_at' => now()->subDay(),
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
        'title' => 'Already Published',
        'status' => SurveyStatus::Published,
        'version' => 2,
        'draft_schema' => $schema,
        'published_schema' => $schema,
        'published_at' => now(),
    ]);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->status)->toBe(SurveyStatus::Published)
        ->and($published->version)->toBe(2)
        ->and($published->published_schema['title'])->toBe('Already Published');
});
