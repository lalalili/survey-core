<?php

use Lalalili\SurveyCore\Actions\CreateSurveyFromBuilderTemplateAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Support\SurveyBuilderTemplateRegistry;

it('provides the MVP survey builder templates', function (): void {
    $templates = app(SurveyBuilderTemplateRegistry::class)->all();

    expect(array_keys($templates))->toBe([
        'event_registration',
        'satisfaction_survey',
        'nps_feedback',
        'course_feedback',
        'lead_capture',
        'after_sales_follow_up',
    ]);
});

it('returns builder-valid schemas for every built-in template', function (string $slug): void {
    $schema = app(SurveyBuilderTemplateRegistry::class)->schema($slug);
    $validated = app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
    $types = collect($validated['pages'])
        ->flatMap(fn (array $page): array => $page['elements'])
        ->pluck('type')
        ->all();

    expect($validated['title'])->not->toBeEmpty()
        ->and($types)->not->toContain(SurveyFieldType::Email->value)
        ->and($types)->not->toContain(SurveyFieldType::Phone->value)
        ->and($types)->not->toContain(SurveyFieldType::Address->value);
})->with(fn (): array => array_keys(app(SurveyBuilderTemplateRegistry::class)->all()));

it('creates a draft survey from a built-in template', function (): void {
    $survey = app(CreateSurveyFromBuilderTemplateAction::class)->execute('event_registration');

    // 本測試刻意驗證「草稿」：此時 survey_fields 尚未同步（要到發佈才寫入），
    // 因此題目設定要從 draft_schema 取。
    $mobile = collect($survey->draft_schema['pages'])
        ->flatMap(fn (array $page): array => $page['elements'] ?? [])
        ->firstWhere('field_key', 'mobile');

    expect($survey->title)->toBe('活動報名')
        ->and($survey->status)->toBe(SurveyStatus::Draft)
        ->and($survey->draft_schema['title'])->toBe('活動報名')
        ->and($mobile['settings']['input_format'])->toBe('mobile_tw');
});
