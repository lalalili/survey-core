<?php

use Lalalili\SurveyCore\Actions\CreateSurveyFromBuilderTemplateAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
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

it('uses generic names for customer-specific survey examples', function (): void {
    $templates = app(SurveyBuilderTemplateRegistry::class)->all();

    expect($templates['satisfaction_survey']['name'])->toBe('通用顧客回饋')
        ->and($templates['after_sales_follow_up']['name'])->toBe('通用售後服務回饋')
        ->and($templates['satisfaction_survey']['description'])->toBe('通用顧客回饋範本')
        ->and($templates['after_sales_follow_up']['description'])->toBe('通用售後服務回饋範本');
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

it('does not enable cookie duplicate detection by default', function (string $slug): void {
    // 被 anomaly_duplicate 標記的回覆會落在 scopeReportable() 之外，等於無聲地
    // 從滿意度報表中消失；而 builder 沒有任何 UI 可以關掉這個設定。
    $schema = app(SurveyBuilderTemplateRegistry::class)->schema($slug);

    expect($schema['settings']['anomaly']['detect_duplicate'])->toBe('none');
})->with(fn (): array => array_keys(app(SurveyBuilderTemplateRegistry::class)->all()));

it('keeps the template anomaly setting through create and publish', function (): void {
    $survey = app(CreateSurveyFromBuilderTemplateAction::class)->execute('satisfaction_survey');

    expect(data_get($survey->draft_schema, 'settings.anomaly.detect_duplicate'))->toBe('none');

    // settings_json 是發佈時才由 PublishSurveyAction 產生的，草稿階段查不到。
    app(PublishSurveyAction::class)->execute($survey);

    expect(data_get($survey->refresh()->settings_json, 'anomaly.detect_duplicate'))->toBe('none');
});

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
