<?php

use Illuminate\Support\Facades\Storage;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

beforeEach(function (): void {
    Storage::fake('local');

    $this->survey = Survey::create(['title' => '選取匯出', 'status' => SurveyStatus::Published]);

    $this->field = SurveyField::create([
        'survey_id' => $this->survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '意見',
        'field_key' => 'feedback',
        'sort_order' => 1,
    ]);
});

function makeExportResponse(Survey $survey, SurveyField $field, string $answer): SurveyResponse
{
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);

    SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $field->id,
        'answer_text' => $answer,
        'snapshot_field_key' => $field->field_key,
        'snapshot_field_label' => $field->label,
        'snapshot_field_type' => $field->type->value,
    ]);

    return $response;
}

it('exports only the selected responses', function (): void {
    $selected = makeExportResponse($this->survey, $this->field, '被選中');
    makeExportResponse($this->survey, $this->field, '未選中');

    $progress = [];

    app(ExportSurveyResponsesAction::class)->exportToDisk(
        $this->survey,
        'local',
        'reports/selected.xlsx',
        null,
        true,
        function (int $processed, int $total) use (&$progress): void {
            $progress[] = [$processed, $total];
        },
        [$selected->id],
    );

    Storage::disk('local')->assertExists('reports/selected.xlsx');

    expect($progress)->not->toBeEmpty()
        ->and(end($progress))->toBe([1, 1]);
});

it('still exports every response when no selection is given', function (): void {
    makeExportResponse($this->survey, $this->field, '第一筆');
    makeExportResponse($this->survey, $this->field, '第二筆');

    $progress = [];

    app(ExportSurveyResponsesAction::class)->exportToDisk(
        $this->survey,
        'local',
        'reports/all.xlsx',
        null,
        true,
        function (int $processed, int $total) use (&$progress): void {
            $progress[] = [$processed, $total];
        },
    );

    expect(end($progress))->toBe([2, 2]);
});
