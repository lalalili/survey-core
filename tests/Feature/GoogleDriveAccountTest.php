<?php

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function makeDriveAccount(array $overrides = []): GoogleDriveAccount
{
    return GoogleDriveAccount::create(array_merge([
        'google_user_id' => 'sub_'.uniqid(),
        'email' => 'owner@example.com',
        'name' => 'Owner',
        'access_token' => 'access-secret',
        'refresh_token' => 'refresh-secret',
        'token_expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
    ], $overrides));
}

it('encrypts oauth tokens at rest but exposes them via the model', function () {
    $account = makeDriveAccount();

    expect($account->fresh()->access_token)->toBe('access-secret')
        ->and($account->fresh()->refresh_token)->toBe('refresh-secret');

    $raw = DB::table('google_drive_accounts')->where('id', $account->id)->first();
    expect($raw->access_token)->not->toBe('access-secret')
        ->and($raw->refresh_token)->not->toBe('refresh-secret');
});

it('hides tokens from array output', function () {
    $array = makeDriveAccount()->toArray();

    expect($array)->not->toHaveKey('access_token')
        ->and($array)->not->toHaveKey('refresh_token');
});

it('detects token expiry', function () {
    expect(makeDriveAccount(['token_expires_at' => now()->subMinute()])->isTokenExpired())->toBeTrue()
        ->and(makeDriveAccount(['token_expires_at' => now()->addMinute()])->isTokenExpired())->toBeFalse();
});

it('binds a survey to one drive account', function () {
    $account = makeDriveAccount();
    $survey = Survey::create(['title' => 'Bound', 'status' => SurveyStatus::Draft, 'google_drive_account_id' => $account->id]);

    expect($survey->googleDriveAccount->is($account))->toBeTrue()
        ->and($account->surveys()->count())->toBe(1);
});

it('knows whether a survey has a file upload field and needs binding', function () {
    $survey = Survey::create(['title' => 'Files', 'status' => SurveyStatus::Draft]);

    expect($survey->hasFileUploadField())->toBeFalse()
        ->and($survey->requiresCloudBindingButUnbound())->toBeFalse();

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::FileUpload,
        'label' => '上傳',
        'field_key' => 'upload',
        'sort_order' => 1,
    ]);

    expect($survey->hasFileUploadField())->toBeTrue()
        ->and($survey->requiresCloudBindingButUnbound())->toBeTrue();

    $survey->update(['google_drive_account_id' => makeDriveAccount()->id]);

    expect($survey->refresh()->requiresCloudBindingButUnbound())->toBeFalse();
});
