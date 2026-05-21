<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Arr;
use Lalalili\SurveyCore\Events\SurveyStarted;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;

class RecordSurveyResponseEventAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Survey $survey,
        string $event,
        ?SurveyCollector $collector = null,
        ?SurveyResponse $response = null,
        ?SurveyRecipient $recipient = null,
        ?string $pageKey = null,
        array $metadata = [],
    ): SurveyResponseEvent {
        $recorded = SurveyResponseEvent::create([
            'survey_id' => $survey->id,
            'survey_collector_id' => $collector?->id,
            'survey_response_id' => $response?->id,
            'event' => $event,
            'page_key' => $pageKey,
            'occurred_at' => now(),
            'metadata_json' => Arr::where($metadata, fn (mixed $value): bool => $value !== null),
        ]);

        if ($event === 'started') {
            SurveyStarted::dispatch($survey, $recipient ?? $response?->recipient);
        }

        return $recorded;
    }
}
