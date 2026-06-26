<?php

use Lalalili\SurveyCore\Actions\ValidateSurveySubmissionAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function phoneSurvey(array $settings = []): Survey
{
    $survey = Survey::create(['title' => 'Phone locale', 'status' => SurveyStatus::Published]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Phone,
        'label' => '手機',
        'field_key' => 'phone',
        'is_required' => true,
        'sort_order' => 1,
        'settings_json' => $settings,
    ]);

    return $survey->load('fields');
}

function validatePhone(Survey $survey, string $value): Closure
{
    return fn () => app(ValidateSurveySubmissionAction::class)->execute($survey, ['phone' => $value]);
}

it('accepts the Taiwan 09 format by default and rejects others', function (): void {
    $survey = phoneSurvey();

    expect(validatePhone($survey, '0912345678'))->not->toThrow(SurveyValidationException::class);
    expect(validatePhone($survey, '12345'))->toThrow(SurveyValidationException::class);
    expect(validatePhone($survey, '+886912345678'))->toThrow(SurveyValidationException::class);
});

it('honours a per-field intl locale override', function (): void {
    $survey = phoneSurvey(['phone_locale' => 'intl']);

    expect(validatePhone($survey, '+886912345678'))->not->toThrow(SurveyValidationException::class);
    // Taiwan numbers start with 0, which the intl pattern rejects.
    expect(validatePhone($survey, '0912345678'))->toThrow(SurveyValidationException::class);
});

it('falls back to the configured default locale when a field has no override', function (): void {
    config()->set('survey-core.phone.default_locale', 'intl');

    $survey = phoneSurvey();

    expect(validatePhone($survey, '+14155552671'))->not->toThrow(SurveyValidationException::class);
    expect(validatePhone($survey, '0912345678'))->toThrow(SurveyValidationException::class);
});

it('keeps the mobile_tw short-text preset on the Taiwan format', function (): void {
    $survey = Survey::create(['title' => 'Mobile preset', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '聯絡手機',
        'field_key' => 'contact',
        'is_required' => true,
        'sort_order' => 1,
        'settings_json' => ['input_format' => 'mobile_tw'],
    ]);
    $survey->load('fields');

    $run = fn (string $value) => fn () => app(ValidateSurveySubmissionAction::class)->execute($survey, ['contact' => $value]);

    expect($run('0912345678'))->not->toThrow(SurveyValidationException::class);
    expect($run('+886912345678'))->toThrow(SurveyValidationException::class);
});
