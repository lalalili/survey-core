<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

it('returns a StreamedResponse for XLSX export', function () {
    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'xlsx');

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))
        ->toContain('spreadsheetml.sheet');
});

it('throws when storing an async XLSX export fails', function () {
    Storage::shouldReceive('disk')
        ->once()
        ->with('broken')
        ->andReturn(new class () {
            public function put(string $path, string $contents): bool
            {
                return false;
            }
        });

    app(ExportSurveyResponsesAction::class)->exportToDisk(
        $this->survey,
        'broken',
        'reports/export.xlsx',
    );
})->throws(RuntimeException::class, 'Unable to store generated export file [reports/export.xlsx] on disk [broken].');

it('throws on unknown export driver', function () {
    app(ExportSurveyResponsesAction::class)->execute($this->survey, 'pdf');
})->throws(InvalidArgumentException::class);

it('exports only answer columns when answersOnly is enabled', function () {
    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'csv', null, answersOnly: true);

    ob_start();
    $response->sendContent();
    $output = ltrim(ob_get_clean(), "\xEF\xBB\xBF");

    $lines = array_values(array_filter(explode("\n", trim($output))));
    $rows = array_map('str_getcsv', $lines);

    // 表頭只有題目欄，無 metadata 與計算欄
    expect($rows[0])->toBe(['Feedback', 'Source'])
        ->and($rows[0])->not->toContain('Response ID')
        ->not->toContain('Recipient Name')
        ->not->toContain('Recipient Email');

    // 資料列只有答案
    expect($rows[1])->toBe(['Excellent!', 'email_blast']);
});

it('exports only the given subset of responses when provided', function () {
    // 第二位收件人 + 第二份回覆
    $recipient2 = SurveyRecipient::create([
        'survey_id' => $this->survey->id,
        'name' => 'Dave',
        'email' => 'dave@example.com',
        'external_id' => 'ext-002',
        'status' => SurveyRecipientStatus::Active,
    ]);

    $token2 = app(GenerateSurveyTokenAction::class)->execute($this->survey, $recipient2, maxSubmissions: 1);
    $resolved2 = app(ResolveSurveyTokenAction::class)->execute($this->survey, $token2->token);
    app(SubmitSurveyResponseAction::class)->execute(
        $this->survey,
        new SubmissionPayload(['feedback' => 'Second answer!'], $resolved2),
    );

    $this->survey->load('responses');
    expect($this->survey->responses)->toHaveCount(2);

    // 只匯出第一位收件人的回覆
    $subset = $this->survey->responses->where('survey_recipient_id', $this->recipient->id);

    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'csv', $subset);

    ob_start();
    $response->sendContent();
    $output = ltrim(ob_get_clean(), "\xEF\xBB\xBF");

    $lines = array_values(array_filter(explode("\n", trim($output))));

    // 1 表頭 + 1 資料列
    expect($lines)->toHaveCount(2);
    expect($output)->toContain('Excellent!')
        ->not->toContain('Second answer!');
});

it('ignores responses that do not belong to the survey', function () {
    // 另一份問卷與其回覆
    $otherSurvey = Survey::create(['title' => 'Other Survey', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $otherSurvey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Other Feedback',
        'field_key' => 'feedback',
        'is_required' => true,
        'sort_order' => 1,
    ]);
    $otherRecipient = SurveyRecipient::create([
        'survey_id' => $otherSurvey->id,
        'name' => 'Eve',
        'email' => 'eve@example.com',
        'external_id' => 'ext-003',
        'status' => SurveyRecipientStatus::Active,
    ]);
    $otherToken = app(GenerateSurveyTokenAction::class)->execute($otherSurvey, $otherRecipient, maxSubmissions: 1);
    $otherResolved = app(ResolveSurveyTokenAction::class)->execute($otherSurvey, $otherToken->token);
    app(SubmitSurveyResponseAction::class)->execute(
        $otherSurvey,
        new SubmissionPayload(['feedback' => 'Other survey answer!'], $otherResolved),
    );

    $this->survey->load('responses');
    $otherSurvey->load('responses');

    // 混入跨問卷的回覆
    $mixed = $this->survey->responses->merge($otherSurvey->responses);

    $response = app(ExportSurveyResponsesAction::class)->execute($this->survey, 'csv', $mixed);

    ob_start();
    $response->sendContent();
    $output = ltrim(ob_get_clean(), "\xEF\xBB\xBF");

    $lines = array_values(array_filter(explode("\n", trim($output))));

    // 跨問卷回覆被濾除，只剩本問卷 1 筆
    expect($lines)->toHaveCount(2);
    expect($output)->toContain('Excellent!')
        ->not->toContain('Other survey answer!');
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
