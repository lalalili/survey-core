<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;

require __DIR__.'/Phase3TestSupport.php';

function publishedVersionedSubmissionSurvey(): Survey
{
    $survey = Survey::create([
        'title' => '填答版本問卷',
        'status' => SurveyStatus::Draft,
        'draft_schema' => [
            'id' => 'submission-version-survey',
            'title' => '填答版本問卷',
            'status' => 'draft',
            'version' => 1,
            'pages' => [[
                'id' => 'page_1',
                'title' => '問題',
                'elements' => [[
                    'id' => 'question_1',
                    'type' => 'single_choice',
                    'field_key' => 'satisfaction',
                    'label' => '滿意度',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [
                        ['id' => 'good', 'label' => '滿意', 'value' => 'good'],
                        ['id' => 'bad', 'label' => '不滿意', 'value' => 'bad'],
                    ],
                    'settings' => ['display' => 'buttons'],
                ]],
            ]],
        ],
    ]);

    return app(PublishSurveyAction::class)->execute($survey)->load('fields');
}

it('binds responses and answers to the published schema snapshot', function () {
    $survey = publishedVersionedSubmissionSurvey();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['satisfaction' => 'good']),
        schemaVersionId: $survey->published_schema_version_id,
    );
    $answer = $response->answers->sole();

    expect($response->schema_version_id)->toBe($survey->published_schema_version_id)
        ->and($answer->snapshot_field_key)->toBe('satisfaction')
        ->and($answer->snapshot_field_label)->toBe('滿意度')
        ->and($answer->snapshot_field_type)->toBe('single_choice')
        ->and($answer->normalizedSnapshotOptions()[0]['label'])->toBe('滿意')
        ->and($answer->normalizedSnapshotSettings()['display'])->toBe('buttons')
        ->and($response->answerMapByFieldKey())->toBe(['satisfaction' => 'good']);
});

it('rejects a stale public form instead of validating it against the current schema', function () {
    $survey = publishedVersionedSubmissionSurvey();
    $staleVersionId = $survey->published_schema_version_id;
    $survey->update(['draft_schema' => array_replace_recursive($survey->draft_schema, [
        'pages' => [0 => ['elements' => [0 => ['label' => '新的滿意度題目']]]],
    ])]);
    $survey = app(PublishSurveyAction::class)->execute($survey->refresh());

    $this->postJson(route('survey.submit', $survey->public_key), [
        'schema_version_id' => $staleVersionId,
        'answers' => ['satisfaction' => 'good'],
    ])->assertConflict()
        ->assertJsonPath('message', '問卷已更新，請重新整理後再填答。');

    expect(SurveyResponse::query()->where('survey_id', $survey->id)->count())->toBe(0);
});

it('renders and submits the current schema version id', function () {
    $survey = publishedVersionedSubmissionSurvey();

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('name="schema_version_id" value="'.$survey->published_schema_version_id.'"', false);

    $this->postJson(route('survey.submit', $survey->public_key), [
        'schema_version_id' => $survey->published_schema_version_id,
        'answers' => ['satisfaction' => 'good'],
    ])->assertCreated();

    expect(SurveyResponse::query()->sole()->schema_version_id)->toBe($survey->published_schema_version_id);
});

it('reports stale schema versions from the submission action as conflict', function () {
    $survey = publishedVersionedSubmissionSurvey();
    $staleVersionId = (int) $survey->published_schema_version_id + 1;

    try {
        app(SubmitSurveyResponseAction::class)->execute(
            $survey,
            new SubmissionPayload(['satisfaction' => 'good']),
            schemaVersionId: $staleVersionId,
        );
        $this->fail('Expected stale schema version to be rejected.');
    } catch (SurveyNotAvailableException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
});
