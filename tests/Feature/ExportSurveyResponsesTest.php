<?php

use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Event::fake();

    $this->survey = Survey::create(['title' => 'Export Test', 'status' => SurveyStatus::Published]);

    SurveyField::create([
        'survey_id' => $this->survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Feedback',
        'field_key' => 'feedback',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $this->survey->id,
        'type' => SurveyFieldType::Hidden,
        'label' => 'Source',
        'field_key' => 'source',
        'is_hidden' => true,
        'is_personalized' => true,
        'personalized_key' => 'campaign_source',
        'sort_order' => 2,
    ]);

    $this->survey->load('fields');

    $this->recipient = SurveyRecipient::create([
        'survey_id' => $this->survey->id,
        'name' => 'Carol',
        'email' => 'carol@example.com',
        'external_id' => 'ext-001',
        'payload_json' => ['campaign_source' => 'email_blast'],
        'status' => SurveyRecipientStatus::Active,
    ]);

    // Submit one response via token
    $token = app(GenerateSurveyTokenAction::class)->execute($this->survey, $this->recipient, maxSubmissions: 1);
    $resolved = app(ResolveSurveyTokenAction::class)->execute($this->survey, $token->token);

    app(SubmitSurveyResponseAction::class)->execute(
        $this->survey,
        new SubmissionPayload(['feedback' => 'Excellent!'], $resolved),
    );
});

it('returns a StreamedResponse for CSV export', function () {
    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'csv');

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('exports headers and row data correctly', function () {
    $output = '';

    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'csv');

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    // Strip UTF-8 BOM
    $output = ltrim($output, "\xEF\xBB\xBF");

    $lines = array_filter(explode("\n", trim($output)));
    $rows = array_map('str_getcsv', array_values($lines));

    // Header row contains field labels
    expect($rows[0])->toContain('Feedback')
        ->toContain('Source')
        ->toContain('Recipient Name')
        ->toContain('Recipient Email');

    // Data row contains submitted values
    expect($rows[1])->toContain('Excellent!')       // visible answer
        ->toContain('email_blast')                  // personalized hidden answer
        ->toContain('Carol')                        // recipient name
        ->toContain('carol@example.com')            // recipient email
        ->toContain('ext-001');                     // external_id
});
