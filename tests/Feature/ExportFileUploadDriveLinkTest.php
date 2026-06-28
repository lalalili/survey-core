<?php

use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

function csvOutput(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

it('exports the google drive link for a file upload answer', function () {
    $survey = Survey::create(['title' => 'Files', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::FileUpload,
        'label' => '文件',
        'field_key' => 'doc',
        'sort_order' => 1,
    ]);
    $survey->load('fields');

    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $media = $response->addMediaFromString('content')->usingFileName('report.pdf')->toMediaCollection('survey_files');
    $media->setCustomProperty('survey_field_key', 'doc');
    $media->setCustomProperty('google_drive_link', 'https://drive.google.com/file/d/abc/view');
    $media->save();

    $output = csvOutput(app(ExportSurveyResponsesAction::class)->execute($survey, 'csv'));

    expect($output)->toContain('https://drive.google.com/file/d/abc/view');
});

it('falls back to the filename when not yet uploaded to drive', function () {
    $survey = Survey::create(['title' => 'Files', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::FileUpload,
        'label' => '文件',
        'field_key' => 'doc',
        'sort_order' => 1,
    ]);
    $survey->load('fields');

    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $media = $response->addMediaFromString('content')->usingFileName('local-only.pdf')->toMediaCollection('survey_files');
    $media->setCustomProperty('survey_field_key', 'doc');
    $media->save();

    $output = csvOutput(app(ExportSurveyResponsesAction::class)->execute($survey, 'csv'));

    expect($output)->toContain('local-only.pdf');
});
