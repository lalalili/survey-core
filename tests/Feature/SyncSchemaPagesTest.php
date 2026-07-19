<?php

use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;

function syncSchema(): array
{
    return [
        'title' => 'Sync Test',
        'pages' => [
            [
                'id' => 'page_a',
                'title' => 'Page A',
                'elements' => [
                    [
                        'id' => 'el_1',
                        'type' => 'short_text',
                        'field_key' => 'name',
                        'label' => 'Name',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [],
                        'settings' => [],
                    ],
                ],
            ],
            [
                'id' => 'page_b',
                'title' => 'Page B',
                'elements' => [
                    [
                        'id' => 'el_2',
                        'type' => 'short_text',
                        'field_key' => 'comment',
                        'label' => 'Comment',
                        'description' => '',
                        'required' => false,
                        'placeholder' => null,
                        'options' => [],
                        'settings' => [],
                    ],
                ],
            ],
        ],
    ];
}

// ── Page upsert ───────────────────────────────────────────────────────────────

it('creates survey_pages from schema on first sync', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, syncSchema());

    expect(SurveyPage::where('survey_id', $survey->id)->count())->toBe(2);

    $pages = $survey->pages()->orderBy('sort_order')->get();
    expect($pages[0]->page_key)->toBe('page_a')
        ->and($pages[1]->page_key)->toBe('page_b')
        ->and($pages[0]->sort_order)->toBe(1)
        ->and($pages[1]->sort_order)->toBe(2);
});

it('updates page title and sort_order on re-sync', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, syncSchema());

    $schema = syncSchema();
    $schema['pages'][0]['title'] = 'Renamed A';
    // Reverse order
    [$schema['pages'][0], $schema['pages'][1]] = [$schema['pages'][1], $schema['pages'][0]];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $pages = $survey->pages()->orderBy('sort_order')->get();
    expect($pages[0]->page_key)->toBe('page_b')
        ->and($pages[1]->page_key)->toBe('page_a');
});

it('deletes pages removed from schema', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, syncSchema());

    $schema = syncSchema();
    unset($schema['pages'][1]);
    $schema['pages'] = array_values($schema['pages']);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    expect(SurveyPage::where('survey_id', $survey->id)->count())->toBe(1);
    expect(SurveyPage::where('survey_id', $survey->id)->first()->page_key)->toBe('page_a');
    expect($survey->fields()->where('field_key', 'comment')->exists())->toBeFalse();
});

it('preserves hidden personalized fields and answers when deleting their former page', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);
    $schema = syncSchema();
    $schema['pages'][1]['elements'] = [[
        'id' => 'el_plate',
        'type' => 'short_text',
        'field_key' => 'plate_number',
        'label' => 'Plate number',
        'description' => '',
        'required' => false,
        'placeholder' => null,
        'is_hidden' => true,
        'personalized_key' => 'regono',
        'options' => [],
        'settings' => [],
    ]];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $field = $survey->fields()->where('field_key', 'plate_number')->firstOrFail();
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);
    $answer = SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $field->id,
        'answer_text' => 'ABC-1234',
    ]);

    unset($schema['pages'][1]);
    $schema['pages'] = array_values($schema['pages']);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey->refresh(), $schema);

    expect($field->refresh()->survey_page_id)->toBeNull()
        ->and($survey->fields()->whereKey($field->id)->exists())->toBeTrue()
        ->and(SurveyAnswer::query()->whereKey($answer->id)->exists())->toBeTrue()
        ->and($survey->pages()->where('page_key', 'page_b')->exists())->toBeFalse();
});

it('preserves legacy hidden fields when deleting their former page', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, syncSchema());

    $stalePage = $survey->pages()->where('page_key', 'page_b')->firstOrFail();
    $legacyField = SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $stalePage->id,
        'type' => 'hidden',
        'label' => 'Campaign',
        'field_key' => 'campaign_id',
        'is_hidden' => true,
        'sort_order' => 99,
    ]);
    $schema = syncSchema();
    unset($schema['pages'][1]);
    $schema['pages'] = array_values($schema['pages']);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey->refresh(), $schema);

    expect($legacyField->refresh()->survey_page_id)->toBeNull()
        ->and($survey->fields()->whereKey($legacyField->id)->exists())->toBeTrue()
        ->and($survey->pages()->where('page_key', 'page_b')->exists())->toBeFalse();
});

// ── Field-to-page assignment ──────────────────────────────────────────────────

it('assigns survey_page_id to each field based on schema page', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, syncSchema());

    $pageA = SurveyPage::where(['survey_id' => $survey->id, 'page_key' => 'page_a'])->first();
    $pageB = SurveyPage::where(['survey_id' => $survey->id, 'page_key' => 'page_b'])->first();

    $nameField = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'name'])->first();
    $commentField = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'comment'])->first();

    expect($nameField->survey_page_id)->toBe($pageA->id)
        ->and($commentField->survey_page_id)->toBe($pageB->id);
});

// ── Jump actions preserved ────────────────────────────────────────────────────

it('preserves go_to_page action in options_json during sync', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $schema = [
        'title' => 'Jump',
        'pages' => [
            [
                'id' => 'pg1',
                'title' => 'P1',
                'elements' => [
                    [
                        'id' => 'el_q',
                        'type' => 'single_choice',
                        'field_key' => 'q',
                        'label' => 'Q',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [
                            ['id' => 'o1', 'label' => 'Skip', 'value' => 'skip', 'action' => ['type' => 'go_to_page', 'target_page_id' => 'pg2']],
                            ['id' => 'o2', 'label' => 'End',  'value' => 'end',  'action' => ['type' => 'end_survey']],
                        ],
                        'settings' => [],
                    ],
                ],
            ],
            [
                'id' => 'pg2',
                'title' => 'P2',
                'elements' => [],
            ],
        ],
    ];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $field = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'q'])->first();

    expect($field->options_json[0])->toMatchArray(['action' => ['type' => 'go_to_page', 'target_page_id' => 'pg2']])
        ->and($field->options_json[1])->toMatchArray(['action' => ['type' => 'end_survey']]);
});

it('strips next_page action from options_json during sync', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $schema = [
        'title' => 'Default',
        'pages' => [
            [
                'id' => 'pg1',
                'title' => 'P1',
                'elements' => [
                    [
                        'id' => 'el_q',
                        'type' => 'single_choice',
                        'field_key' => 'q',
                        'label' => 'Q',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [
                            ['id' => 'o1', 'label' => 'Yes', 'value' => 'yes', 'action' => ['type' => 'next_page']],
                        ],
                        'settings' => [],
                    ],
                ],
            ],
        ],
    ];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $field = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'q'])->first();
    expect(array_key_exists('action', $field->options_json[0]))->toBeFalse();
});

// ── Personalization guarding ─────────────────────────────────────────────────

it('keeps personalization on free-text fields', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $schema = [
        'title' => 'Test',
        'pages' => [[
            'id' => 'page_a',
            'title' => 'P',
            'elements' => [[
                'id' => 'el_1',
                'type' => 'short_text',
                'field_key' => 'first_name',
                'label' => 'Name',
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'is_hidden' => true,
                'personalized_key' => 'first_name',
                'options' => [],
                'settings' => [],
            ]],
        ]],
    ];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $field = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'first_name'])->first();
    expect($field->is_hidden)->toBeTrue()
        ->and($field->is_personalized)->toBeTrue()
        ->and($field->personalized_key)->toBe('first_name');
});

it('strips personalization from choice fields that cannot map a raw value to options', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $schema = [
        'title' => 'Test',
        'pages' => [[
            'id' => 'page_a',
            'title' => 'P',
            'elements' => [[
                'id' => 'el_1',
                'type' => 'single_choice',
                'field_key' => 'plan',
                'label' => 'Plan',
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'is_hidden' => true,
                'personalized_key' => 'plan',
                'options' => [
                    ['id' => 'o1', 'label' => 'A', 'value' => 'a'],
                    ['id' => 'o2', 'label' => 'B', 'value' => 'b'],
                ],
                'settings' => [],
            ]],
        ]],
    ];

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $field = SurveyField::where(['survey_id' => $survey->id, 'field_key' => 'plan'])->first();
    expect($field->is_hidden)->toBeFalse()
        ->and($field->is_personalized)->toBeFalse()
        ->and($field->personalized_key)->toBeNull();
});
