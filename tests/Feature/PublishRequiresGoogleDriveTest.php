<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use Lalalili\SurveyCore\Models\Survey;

function fileUploadSurvey(array $overrides = []): Survey
{
    $survey = Survey::create(array_merge(['title' => 'Upload', 'status' => SurveyStatus::Draft], $overrides));
    $survey->update([
        'draft_schema' => [
            'id' => $survey->id,
            'title' => 'Upload',
            'status' => 'draft',
            'version' => 1,
            'pages' => [[
                'id' => 'page_1',
                'title' => 'Page 1',
                'elements' => [[
                    'id' => 'q1',
                    'type' => 'file_upload',
                    'field_key' => 'doc',
                    'label' => '上傳文件',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => ['max_size_mb' => 5],
                ]],
            ]],
        ],
    ]);

    return $survey->refresh();
}

it('blocks publishing a file-upload survey without a google drive binding', function () {
    $survey = fileUploadSurvey();

    expect(fn () => app(PublishSurveyAction::class)->execute($survey))
        ->toThrow(SurveyNotAvailableException::class);

    expect($survey->refresh()->status)->toBe(SurveyStatus::Draft);
});

it('allows publishing once a google drive account is bound', function () {
    $account = GoogleDriveAccount::create(['google_user_id' => 'sub-x', 'email' => 'x@y.z']);
    $survey = fileUploadSurvey(['google_drive_account_id' => $account->id]);

    $published = app(PublishSurveyAction::class)->execute($survey);

    expect($published->status)->toBe(SurveyStatus::Published);
});

it('does not require binding for surveys without a file upload field', function () {
    $survey = Survey::create(['title' => 'Plain', 'status' => SurveyStatus::Draft]);
    $survey->update([
        'draft_schema' => [
            'id' => $survey->id, 'title' => 'Plain', 'status' => 'draft', 'version' => 1,
            'pages' => [[
                'id' => 'page_1', 'title' => 'P1',
                'elements' => [[
                    'id' => 'q1', 'type' => 'short_text', 'field_key' => 'name', 'label' => '姓名',
                    'description' => '', 'required' => false, 'placeholder' => null, 'options' => [], 'settings' => [],
                ]],
            ]],
        ],
    ]);

    expect(app(PublishSurveyAction::class)->execute($survey->refresh())->status)->toBe(SurveyStatus::Published);
});
