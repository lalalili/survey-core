<?php

use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

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
