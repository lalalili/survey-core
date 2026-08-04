<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\SurveyReportCacheRevision;

class ReviewSurveyResponseAction
{
    public function __construct(private SurveyReportCacheRevision $reportCacheRevision)
    {
    }

    public function execute(
        SurveyResponse $response,
        SurveyResponseQualityStatus $status,
        ?string $notes,
        string $source,
        ?Authenticatable $causer = null,
    ): SurveyResponse {
        return DB::transaction(function () use ($response, $status, $notes, $source, $causer): SurveyResponse {
            $response->refresh();

            $oldStatus = $response->quality_status;
            $oldNotes = $response->notes;
            $statusChanged = $oldStatus !== $status;
            $notesChanged = $oldNotes !== $notes;

            if (! $statusChanged && ! $notesChanged) {
                return $response;
            }

            $response->update([
                'quality_status' => $status,
                'notes' => $notes,
            ]);

            $logger = activity('survey_response_review')
                ->event('review_updated')
                ->performedOn($response)
                ->withProperties([
                    'source' => $source,
                    'survey_id' => $response->survey_id,
                    'response_number' => $response->response_number,
                    'old' => [
                        'quality_status' => $oldStatus->value,
                        'notes' => $oldNotes,
                    ],
                    'attributes' => [
                        'quality_status' => $status->value,
                        'notes' => $notes,
                    ],
                ]);

            if ($causer instanceof Model) {
                $logger->causedBy($causer);
            }

            $logger->log('更新問卷回填審查狀態');

            if ($statusChanged) {
                $this->reportCacheRevision->bump();
            }

            return $response->refresh();
        });
    }
}
