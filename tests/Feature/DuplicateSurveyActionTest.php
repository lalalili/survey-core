<?php

use Lalalili\SurveyCore\Actions\DuplicateSurveyAction;
use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function duplicateSurveyWithPages(): Survey
{
    $survey = Survey::create(['title' => 'Original', 'status' => SurveyStatus::Published]);

    $schema = [
        'title' => 'Original',
        'pages' => [
            [
                'id' => 'page_1',
                'title' => 'Page 1',
                'elements' => [
                    [
                        'id' => 'el_1',
                        'type' => 'single_choice',
                        'field_key' => 'q1',
                        'label' => 'Choose',
                        'description' => '',
                        'required' => true,
                        'placeholder' => null,
                        'options' => [
                            [
                                'id' => 'o1',
                                'label' => 'Skip ahead',
                                'value' => 'skip',
                                'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_2'],
                            ],
                            ['id' => 'o2', 'label' => 'Stay', 'value' => 'stay'],
                        ],
                        'settings' => [],
                    ],
                ],
            ],
            [
                'id' => 'page_2',
                'title' => 'Page 2',
                'elements' => [
                    [
                        'id' => 'el_2',
                        'type' => 'short_text',
                        'field_key' => 'q2',
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

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    return $survey->refresh();
}

it('clones survey pages and re-points cloned fields to the new pages', function (): void {
    $survey = duplicateSurveyWithPages();

    $clone = app(DuplicateSurveyAction::class)->execute($survey);

    expect($clone->status)->toBe(SurveyStatus::Draft)
        ->and($clone->title)->toBe('Original (Copy)')
        ->and($clone->version)->toBe(1)
        ->and($clone->pages()->count())->toBe($survey->pages()->count());

    // Page keys are preserved so jump rules remain valid.
    expect($clone->pages()->pluck('page_key')->sort()->values()->all())
        ->toBe($survey->pages()->pluck('page_key')->sort()->values()->all());

    // Every cloned field points to a page that belongs to the clone, not the original.
    $clonePageIds = $clone->pages()->pluck('id')->all();
    $clone->fields->each(function (SurveyField $field) use ($clonePageIds): void {
        expect($field->survey_page_id)->not->toBeNull()
            ->and(in_array($field->survey_page_id, $clonePageIds, true))->toBeTrue();
    });
});

it('keeps jump rule target_page_id valid on the cloned survey', function (): void {
    $survey = duplicateSurveyWithPages();

    $clone = app(DuplicateSurveyAction::class)->execute($survey);

    $jumpField = $clone->fields()->where('field_key', 'q1')->firstOrFail();
    $action = collect($jumpField->options_json)
        ->firstWhere(fn (array $option): bool => ($option['action']['type'] ?? null) === 'go_to_page');

    $targetPageKey = $action['action']['target_page_id'];

    expect($targetPageKey)->toBe('page_2')
        ->and($clone->pages()->where('page_key', $targetPageKey)->exists())->toBeTrue();
});
