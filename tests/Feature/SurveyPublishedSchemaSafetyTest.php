<?php

use Lalalili\SurveyCore\Actions\BuildSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\RestoreSurveyPublishedSchemaAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

function safetySchema(array $elementOverrides = []): array
{
    return [
        'id' => 'safety-survey',
        'title' => '正式問卷',
        'status' => 'draft',
        'version' => 1,
        'settings' => ['description' => '正式說明'],
        'pages' => [[
            'id' => 'page_1',
            'title' => '問題',
            'elements' => [[
                'id' => 'question_1',
                'type' => 'single_choice',
                'field_key' => 'choice',
                'label' => '選擇題',
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'options' => [
                    ['id' => 'yes', 'label' => '是', 'value' => 'yes'],
                    ['id' => 'no', 'label' => '否', 'value' => 'no'],
                ],
                'settings' => [],
                ...$elementOverrides,
            ]],
        ]],
    ];
}

function publishedSafetySurvey(): Survey
{
    return app(PublishSurveyAction::class)->execute(Survey::create([
        'title' => '正式問卷',
        'status' => SurveyStatus::Draft,
        'version' => 0,
        'draft_schema' => safetySchema(),
    ]));
}

function addSafetyAnswer(Survey $survey, string $value = 'yes'): SurveyAnswer
{
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);

    return SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $survey->fields()->where('field_key', 'choice')->valueOrFail('id'),
        'answer_text' => $value,
    ]);
}

it('keeps autosaved drafts isolated from live survey attributes fields and public rendering', function () {
    $survey = publishedSafetySurvey();
    $draft = safetySchema(['label' => '草稿題目']);
    $draft['title'] = '草稿標題';
    $draft['settings']['description'] = '草稿說明';

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, $draft);

    expect($saved->title)->toBe('正式問卷')
        ->and($saved->description)->toBe('正式說明')
        ->and($saved->fields()->where('field_key', 'choice')->value('label'))->toBe('選擇題')
        ->and($saved->draft_schema['title'])->toBe('草稿標題')
        ->and(app(BuildSurveyBuilderSchemaAction::class)->execute($saved)['title'])->toBe('草稿標題');

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('選擇題')
        ->assertDontSee('草稿題目');
});

it('restores the published snapshot to draft without changing live data', function () {
    $survey = publishedSafetySurvey();
    $survey->update(['draft_schema' => safetySchema(['label' => '未發布題目'])]);

    $restored = app(RestoreSurveyPublishedSchemaAction::class)->execute($survey->refresh());

    expect($restored->draft_schema)->toBe($restored->published_schema)
        ->and($restored->fields()->where('field_key', 'choice')->value('label'))->toBe('選擇題')
        ->and($restored->title)->toBe('正式問卷');
});

it('retires a removed answered field while preserving answers and hiding it publicly', function () {
    $survey = publishedSafetySurvey();
    $answer = addSafetyAnswer($survey);
    $draft = safetySchema();
    $draft['pages'][0]['elements'] = [];
    $survey->update(['draft_schema' => $draft]);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());
    $field = $published->fields()->where('field_key', 'choice')->sole();

    expect($field->retired_at)->not->toBeNull()
        ->and($field->survey_page_id)->toBeNull()
        ->and($answer->fresh())->not->toBeNull()
        ->and($published->activeFields()->whereKey($field)->exists())->toBeFalse();

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertDontSee('選擇題');
});

it('physically deletes a removed unanswered field', function () {
    $survey = publishedSafetySurvey();
    $fieldId = $survey->fields()->where('field_key', 'choice')->valueOrFail('id');
    $draft = safetySchema();
    $draft['pages'][0]['elements'] = [];
    $survey->update(['draft_schema' => $draft]);

    app(PublishSurveyAction::class)->execute($survey->refresh());

    expect(SurveyField::query()->whereKey($fieldId)->exists())->toBeFalse();
});

it('rejects destructive changes to answered fields and rolls back publishing', function (array $overrides, string $message): void {
    $survey = publishedSafetySurvey();
    addSafetyAnswer($survey);
    $survey->update(['draft_schema' => safetySchema($overrides)]);
    $version = $survey->version;

    try {
        app(PublishSurveyAction::class)->execute($survey->refresh());
        $this->fail('Expected destructive schema change to be rejected.');
    } catch (SurveyValidationException $exception) {
        expect(collect($exception->getErrors())->flatten()->implode(' '))->toContain($message);
    }

    expect($survey->refresh()->version)->toBe($version)
        ->and($survey->published_schema['pages'][0]['elements'][0]['field_key'])->toBe('choice')
        ->and($survey->fields()->where('field_key', 'choice')->value('type')->value)->toBe('single_choice');
})->with([
    'field key' => [['field_key' => 'renamed_choice'], 'field_key'],
    'field type' => [['type' => 'short_text', 'options' => []], '不能修改題型'],
    'used option value' => [['options' => [['id' => 'yes', 'label' => '是', 'value' => 'changed'], ['id' => 'no', 'label' => '否', 'value' => 'no']]], 'option value'],
]);

it('allows option label changes while keeping a used option value stable', function () {
    $survey = publishedSafetySurvey();
    addSafetyAnswer($survey);
    $survey->update(['draft_schema' => safetySchema([
        'options' => [
            ['id' => 'yes', 'label' => '同意', 'value' => 'yes'],
            ['id' => 'no', 'label' => '不同意', 'value' => 'no'],
        ],
    ])]);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->fields()->where('field_key', 'choice')->sole()->options_json[0]['label'])->toBe('同意');
});

it('still protects an answered field when the builder recreates it with a new element id', function (array $overrides, string $message) {
    $survey = publishedSafetySurvey();
    addSafetyAnswer($survey);
    $survey->update(['draft_schema' => safetySchema([
        'id' => 'recreated_question',
        ...$overrides,
    ])]);

    expect(fn () => app(PublishSurveyAction::class)->execute($survey->refresh()))
        ->toThrow(SurveyValidationException::class, '問卷結構變更會破壞歷史答案。');

    try {
        app(PublishSurveyAction::class)->execute($survey->refresh());
    } catch (SurveyValidationException $exception) {
        expect(collect($exception->getErrors())->flatten()->implode(' '))->toContain($message);
    }
})->with([
    'changed type' => [['type' => 'short_text', 'options' => []], '不能修改題型'],
    'changed used option value' => [['options' => [['id' => 'yes', 'label' => '是', 'value' => 'changed'], ['id' => 'no', 'label' => '否', 'value' => 'no']]], 'option value'],
]);

it('reactivates an answered field recreated with a new element id when its protected structure is unchanged', function () {
    $survey = publishedSafetySurvey();
    addSafetyAnswer($survey);
    $survey->update(['draft_schema' => safetySchema(['id' => 'recreated_question'])]);

    $published = app(PublishSurveyAction::class)->execute($survey->refresh());

    expect($published->fields()->where('field_key', 'choice')->sole()->retired_at)->toBeNull();
});

it('reactivates a retired field when it is re-added with the same key', function () {
    $survey = publishedSafetySurvey();
    addSafetyAnswer($survey);
    $removed = safetySchema();
    $removed['pages'][0]['elements'] = [];
    $survey->update(['draft_schema' => $removed]);
    $retired = app(PublishSurveyAction::class)->execute($survey->refresh());
    $fieldId = $retired->fields()->where('field_key', 'choice')->valueOrFail('id');

    $retired->update(['draft_schema' => safetySchema()]);
    $republished = app(PublishSurveyAction::class)->execute($retired->refresh());

    $field = $republished->fields()->where('field_key', 'choice')->sole();
    expect($field->id)->toBe($fieldId)
        ->and($field->retired_at)->toBeNull()
        ->and($field->survey_page_id)->not->toBeNull();
});
