<?php

use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

function uploadSchema(): array
{
    return [
        'id' => 1,
        'title' => 'Upload Survey',
        'status' => 'draft',
        'version' => 1,
        'pages' => [[
            'id' => 'page_1',
            'title' => 'Page 1',
            'elements' => [
                [
                    'id' => 'q_file',
                    'type' => 'file_upload',
                    'field_key' => 'attachment',
                    'label' => '上傳檔案',
                    'description' => '',
                    'required' => true,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => ['max_size_mb' => 8, 'allowed_mimes' => ['pdf', 'jpg']],
                ],
                [
                    'id' => 'q_sign',
                    'type' => 'signature',
                    'field_key' => 'sign',
                    'label' => '請簽名',
                    'description' => '',
                    'required' => true,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => [],
                ],
            ],
        ]],
    ];
}

it('accepts file_upload and signature elements during schema validation', function () {
    $validated = app(ValidateSurveyBuilderSchemaAction::class)->execute(uploadSchema());

    $types = collect($validated['pages'][0]['elements'])->pluck('type')->all();

    expect($types)->toContain('file_upload', 'signature');
});

it('syncs file_upload settings to the persisted field', function () {
    $survey = Survey::create(['title' => 'Upload Survey', 'status' => SurveyStatus::Draft]);

    app(SaveSurveyDraftSchemaAction::class)->execute($survey, uploadSchema());

    $field = $survey->fields()->where('field_key', 'attachment')->first();

    expect($field)->not->toBeNull()
        ->and($field->type->value)->toBe('file_upload')
        ->and($field->settings_json['max_size_mb'])->toBe(8)
        ->and($field->settings_json['allowed_mimes'])->toBe(['pdf', 'jpg']);

    $signature = $survey->fields()->where('field_key', 'sign')->first();

    expect($signature)->not->toBeNull()
        ->and($signature->type->value)->toBe('signature');
});
