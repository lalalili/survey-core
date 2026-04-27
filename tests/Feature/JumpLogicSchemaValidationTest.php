<?php

use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;

// ── Helpers ──────────────────────────────────────────────────────────────────

function twoPageSchema(array $page1Elements = [], array $page2Elements = []): array
{
    return [
        'title' => 'Jump Test',
        'pages' => [
            [
                'id'       => 'page_1',
                'title'    => 'Page 1',
                'elements' => $page1Elements,
            ],
            [
                'id'       => 'page_2',
                'title'    => 'Page 2',
                'elements' => $page2Elements,
            ],
        ],
    ];
}

function singleChoiceElement(array $options, string $fieldKey = 'q1'): array
{
    return [
        'id'          => 'el_1',
        'type'        => 'single_choice',
        'field_key'   => $fieldKey,
        'label'       => 'Choose',
        'description' => '',
        'required'    => true,
        'placeholder' => null,
        'options'     => $options,
        'settings'    => [],
    ];
}

// ── Valid schemas ─────────────────────────────────────────────────────────────

it('accepts options without any action (action key absent)', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            ['id' => 'o1', 'label' => 'Yes', 'value' => 'yes'],
            ['id' => 'o2', 'label' => 'No',  'value' => 'no'],
        ]),
    ]);

    expect(app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))->toBeArray();
});

it('accepts next_page action on single_choice', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            ['id' => 'o1', 'label' => 'Yes', 'value' => 'yes', 'action' => ['type' => 'next_page']],
        ]),
    ]);

    expect(app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))->toBeArray();
});

it('accepts end_survey action on single_choice', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            ['id' => 'o1', 'label' => 'End', 'value' => 'end', 'action' => ['type' => 'end_survey']],
        ]),
    ]);

    expect(app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))->toBeArray();
});

it('accepts go_to_page pointing to a later page', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            [
                'id'     => 'o1',
                'label'  => 'Skip',
                'value'  => 'skip',
                'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_2'],
            ],
        ]),
    ]);

    expect(app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))->toBeArray();
});

// ── Invalid action type ───────────────────────────────────────────────────────

it('rejects an unknown action type', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            ['id' => 'o1', 'label' => 'X', 'value' => 'x', 'action' => ['type' => 'teleport']],
        ]),
    ]);

    app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
})->throws(SurveyValidationException::class);

// ── Jump action on non-single_choice ─────────────────────────────────────────

it('rejects a jump action on a multiple_choice field', function () {
    $schema = twoPageSchema([
        [
            'id'          => 'el_mc',
            'type'        => 'multiple_choice',
            'field_key'   => 'tags',
            'label'       => 'Tags',
            'description' => '',
            'required'    => false,
            'placeholder' => null,
            'options'     => [
                ['id' => 'o1', 'label' => 'A', 'value' => 'a', 'action' => ['type' => 'end_survey']],
            ],
            'settings' => [],
        ],
    ]);

    app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
})->throws(SurveyValidationException::class);

// ── go_to_page target validation ──────────────────────────────────────────────

it('rejects go_to_page with a non-existent target_page_id', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            [
                'id'     => 'o1',
                'label'  => 'Go',
                'value'  => 'go',
                'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_xyz'],
            ],
        ]),
    ]);

    app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
})->throws(SurveyValidationException::class);

it('rejects a backward jump (target page index ≤ current page index)', function () {
    // page_2 has a single_choice that jumps back to page_1
    $schema = [
        'title' => 'Backward',
        'pages' => [
            [
                'id'       => 'page_1',
                'title'    => 'Page 1',
                'elements' => [
                    [
                        'id'          => 'el_p1',
                        'type'        => 'short_text',
                        'field_key'   => 'name',
                        'label'       => 'Name',
                        'description' => '',
                        'required'    => true,
                        'placeholder' => null,
                        'options'     => [],
                        'settings'    => [],
                    ],
                ],
            ],
            [
                'id'       => 'page_2',
                'title'    => 'Page 2',
                'elements' => [
                    singleChoiceElement([
                        [
                            'id'     => 'o1',
                            'label'  => 'Go back',
                            'value'  => 'back',
                            'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_1'],
                        ],
                    ], 'q2'),
                ],
            ],
        ],
    ];

    app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
})->throws(SurveyValidationException::class);

it('rejects a self-referential jump (same page)', function () {
    $schema = twoPageSchema([
        singleChoiceElement([
            [
                'id'     => 'o1',
                'label'  => 'Loop',
                'value'  => 'loop',
                'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_1'],
            ],
        ]),
    ]);

    app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
})->throws(SurveyValidationException::class);

// ── select type supports jump ─────────────────────────────────────────────────

it('accepts go_to_page action on a select field', function () {
    $schema = twoPageSchema([
        [
            'id'          => 'el_sel',
            'type'        => 'select',
            'field_key'   => 'region',
            'label'       => 'Region',
            'description' => '',
            'required'    => true,
            'placeholder' => null,
            'options'     => [
                [
                    'id'     => 'o1',
                    'label'  => 'North',
                    'value'  => 'north',
                    'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_2'],
                ],
                ['id' => 'o2', 'label' => 'South', 'value' => 'south'],
            ],
            'settings' => [],
        ],
    ]);

    expect(app(ValidateSurveyBuilderSchemaAction::class)->execute($schema))->toBeArray();
});
