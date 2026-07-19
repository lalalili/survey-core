<?php

use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

function scheduledSurveySchema(string $title = 'Scheduled'): array
{
    return [
        'title' => $title,
        'status' => 'draft',
        'version' => 1,
        'settings' => [],
        'pages' => [
            [
                'id' => 'page_1',
                'kind' => 'question',
                'title' => '第一頁',
                'elements' => [[
                    'id' => 'q_1',
                    'type' => 'short_text',
                    'field_key' => 'feedback',
                    'label' => '您的意見',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => [],
                ]],
            ],
        ],
    ];
}

it('publishes a due draft through the full publish flow', function (): void {
    $survey = Survey::create([
        'title' => 'Scheduled',
        'status' => SurveyStatus::Draft,
        'starts_at' => now()->subMinute(),
    ]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, scheduledSurveySchema());

    $this->artisan('survey:schedule')->assertSuccessful();

    $survey->refresh();

    expect($survey->status)->toBe(SurveyStatus::Published)
        // 直接改 status 不會做這兩件事，公開頁會變成一份沒有題目的空白問卷。
        ->and($survey->fields()->count())->toBe(1)
        ->and($survey->published_schema_version_id)->not->toBeNull();
});

it('leaves drafts whose start time has not arrived', function (): void {
    $survey = Survey::create([
        'title' => 'Future',
        'status' => SurveyStatus::Draft,
        'starts_at' => now()->addDay(),
    ]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, scheduledSurveySchema('Future'));

    $this->artisan('survey:schedule')->assertSuccessful();

    expect($survey->refresh()->status)->toBe(SurveyStatus::Draft);
});

it('closes published surveys past their end time', function (): void {
    $survey = Survey::create([
        'title' => 'Ending',
        'status' => SurveyStatus::Published,
        'ends_at' => now()->subMinute(),
    ]);

    $this->artisan('survey:schedule')->assertSuccessful();

    expect($survey->refresh()->status)->toBe(SurveyStatus::Closed);
});

it('keeps publishing the remaining surveys when one fails', function (): void {
    $broken = Survey::create([
        'title' => 'Broken',
        'status' => SurveyStatus::Draft,
        'starts_at' => now()->subMinute(),
    ]);
    // 沒有 draft_schema 也沒有欄位：schema 驗證會失敗。
    $broken->forceFill(['draft_schema' => ['not' => 'a valid schema']])->save();

    $healthy = Survey::create([
        'title' => 'Healthy',
        'status' => SurveyStatus::Draft,
        'starts_at' => now()->subMinute(),
    ]);
    app(SaveSurveyDraftSchemaAction::class)->execute($healthy, scheduledSurveySchema('Healthy'));

    $this->artisan('survey:schedule')->assertFailed();

    expect($broken->refresh()->status)->toBe(SurveyStatus::Draft)
        ->and($healthy->refresh()->status)->toBe(SurveyStatus::Published);
});
