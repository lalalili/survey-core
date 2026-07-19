<?php

use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;
use Lalalili\SurveyCore\Actions\EvaluateAnswerRuleTreeAction;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Listeners\DispatchSurveySubmittedWebhook;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

function snapshotResponseFixture(): array
{
    $survey = Survey::create([
        'title' => 'Snapshot consumers',
        'status' => SurveyStatus::Published,
    ]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Original question',
        'field_key' => 'original_key',
        'options_json' => [
            ['label' => 'Original option', 'value' => 'original_value'],
        ],
        'sort_order' => 1,
    ]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
    ]);
    $answer = SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $field->id,
        'answer_text' => 'original_value',
        'snapshot_field_key' => 'original_key',
        'snapshot_field_label' => 'Original question',
        'snapshot_field_type' => SurveyFieldType::SingleChoice->value,
        'snapshot_options_json' => [
            ['label' => 'Original option', 'value' => 'original_value'],
        ],
    ]);

    $field->update([
        'label' => 'Changed question',
        'field_key' => 'changed_key',
        'options_json' => [
            ['label' => 'Changed option', 'value' => 'changed_value'],
        ],
        'retired_at' => now(),
    ]);

    return [$survey, $response, $answer];
}

it('exports current fields and retired snapshot fields as a union', function (): void {
    [$survey] = snapshotResponseFixture();
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'New question',
        'field_key' => 'new_key',
        'sort_order' => 2,
    ]);

    $export = app(ExportSurveyResponsesAction::class)->execute($survey, 'csv');
    ob_start();
    $export->sendContent();
    $output = ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($output)))));

    expect($rows[0])->toContain('New question')
        ->toContain('Original question')
        ->not->toContain('Changed question')
        ->and($rows[1])->toContain('original_value');
});

it('aggregates retired answers using snapshot field metadata', function (): void {
    [$survey, , $firstAnswer] = snapshotResponseFixture();
    $laterResponse = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now()->addMinute(),
    ]);
    SurveyAnswer::create([
        'survey_response_id' => $laterResponse->id,
        'survey_field_id' => $firstAnswer->survey_field_id,
        'answer_text' => 'original_value',
        'snapshot_field_key' => 'original_key',
        'snapshot_field_label' => 'Later question label',
        'snapshot_field_type' => SurveyFieldType::SingleChoice->value,
        'snapshot_options_json' => [
            ['label' => 'Later option label', 'value' => 'original_value'],
        ],
    ]);

    $analytics = app(ComputeSurveyAnalyticsAction::class)->execute($survey);
    $question = collect($analytics['questions'])->firstWhere('field_key', 'original_key');

    expect($question)->toMatchArray([
        'field_key' => 'original_key',
        'label' => 'Later question label',
        'type' => SurveyFieldType::SingleChoice->value,
        'answered' => 2,
        'distribution' => [
            ['value' => 'original_value', 'label' => 'Later option label', 'count' => 2],
        ],
    ]);
});

it('uses snapshot field keys for rules and webhook payloads', function (): void {
    [$survey, $response] = snapshotResponseFixture();
    $response->load('answers.field');

    expect(app(EvaluateAnswerRuleTreeAction::class)->execute($response, [
        'field' => 'original_key',
        'operator' => '=',
        'value' => 'original_value',
    ]))->toBeTrue();

    Http::fake(['*' => Http::response('OK')]);
    config()->set('survey-core.webhooks.endpoints', ['https://example.com/webhook']);
    app(DispatchSurveySubmittedWebhook::class)->handle(new SurveySubmitted($response, $survey));

    Http::assertSent(fn ($request): bool => $request['answers']['original_key'] === 'original_value'
        && ! isset($request['answers']['changed_key']));
});
