<?php

use Lalalili\SurveyCore\Jobs\UploadResponseFilesToGoogleDriveJob;
use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\GoogleDriveClientFactory;
use Lalalili\SurveyCore\Tests\Fixtures\RecordingDriveClientFactory;

it('uploads response media to drive and records file id and link', function () {
    $factory = new RecordingDriveClientFactory;
    app()->instance(GoogleDriveClientFactory::class, $factory);

    $account = GoogleDriveAccount::create(['google_user_id' => 'sub-1', 'email' => 'a@b.c']);
    $survey = Survey::create([
        'title' => 'Files',
        'status' => 'published',
        'google_drive_account_id' => $account->id,
        'google_drive_folder_id' => 'folder-1',
    ]);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);

    $media = $response->addMediaFromString('hello world')
        ->usingFileName('answer.txt')
        ->toMediaCollection('survey_files');

    (new UploadResponseFilesToGoogleDriveJob($response->id))->handle($factory);

    expect($factory->uploads)->toHaveCount(1)
        ->and($factory->uploads[0]['folder'])->toBe('folder-1')
        ->and($factory->uploads[0]['name'])->toBe('answer.txt');

    $media->refresh();
    expect($media->getCustomProperty('google_drive_file_id'))->toBe('drive-1')
        ->and($media->getCustomProperty('google_drive_link'))->toBe('https://drive.google.com/file/d/drive-1/view');
});

it('skips media already uploaded', function () {
    $factory = new RecordingDriveClientFactory;
    app()->instance(GoogleDriveClientFactory::class, $factory);

    $account = GoogleDriveAccount::create(['google_user_id' => 'sub-2', 'email' => 'a@b.c']);
    $survey = Survey::create(['title' => 'Files', 'status' => 'published', 'google_drive_account_id' => $account->id]);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);

    $media = $response->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection('survey_files');
    $media->setCustomProperty('google_drive_file_id', 'already');
    $media->save();

    (new UploadResponseFilesToGoogleDriveJob($response->id))->handle($factory);

    expect($factory->uploads)->toHaveCount(0);
});

it('does nothing when the survey is not bound', function () {
    $factory = new RecordingDriveClientFactory;
    app()->instance(GoogleDriveClientFactory::class, $factory);

    $survey = Survey::create(['title' => 'Files', 'status' => 'published']);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    $response->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection('survey_files');

    (new UploadResponseFilesToGoogleDriveJob($response->id))->handle($factory);

    expect($factory->uploads)->toHaveCount(0);
});
