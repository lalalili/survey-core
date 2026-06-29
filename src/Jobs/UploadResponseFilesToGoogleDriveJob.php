<?php

namespace Lalalili\SurveyCore\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\GoogleDriveClientFactory;

/**
 * Pushes a response's uploaded files to the survey's bound Google Drive folder
 * and records the resulting Drive file id/link on each media item. Runs async
 * so a slow/failing Drive API never blocks the respondent's submission.
 */
class UploadResponseFilesToGoogleDriveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $surveyResponseId) {}

    public function handle(GoogleDriveClientFactory $clients): void
    {
        $response = SurveyResponse::with('survey.googleDriveAccount')->find($this->surveyResponseId);

        if ($response === null) {
            return;
        }

        $survey = $response->survey;

        if ($survey->google_drive_account_id === null) {
            return;
        }

        $account = $survey->googleDriveAccount;

        if ($account === null) {
            return;
        }

        $deleteLocal = (bool) config('survey-core.google_drive.delete_local_after_upload', false);
        $mediaItems = $response->getMedia('survey_files')
            ->reject(fn ($media): bool => (string) $media->getCustomProperty('google_drive_file_id') !== '');

        if ($mediaItems->isEmpty()) {
            return;
        }

        $rootFolderId = $clients->ensureFolder($account, $this->rootFolderName());
        $surveyFolderId = $clients->ensureFolder($account, $this->surveyFolderName($survey->id, $survey->title), $survey->google_drive_folder_id, $rootFolderId);

        if ($survey->google_drive_folder_id !== $surveyFolderId) {
            $survey->forceFill(['google_drive_folder_id' => $surveyFolderId])->save();
        }

        $responseFolderId = $clients->ensureFolder($account, $this->responseFolderName($response->id), null, $surveyFolderId);

        foreach ($mediaItems as $media) {
            $stream = $media->stream();

            try {
                $result = $clients->uploadFile(
                    $account,
                    $responseFolderId,
                    $media->file_name,
                    $stream,
                    $media->mime_type ?: 'application/octet-stream',
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $media->setCustomProperty('google_drive_file_id', $result['id']);
            $media->setCustomProperty('google_drive_link', $result['link']);
            $media->save();

            if ($deleteLocal) {
                $media->delete();
            }
        }

        Log::info('survey google drive upload complete', [
            'response_id' => $response->id,
            'survey_id' => $survey->id,
        ]);
    }

    private function rootFolderName(): string
    {
        return 'Survey File Upload';
    }

    private function surveyFolderName(int $surveyId, string $surveyTitle): string
    {
        return '問卷 #'.$surveyId.' - '.$surveyTitle;
    }

    private function responseFolderName(int $responseId): string
    {
        return '回覆 #'.$responseId;
    }
}
