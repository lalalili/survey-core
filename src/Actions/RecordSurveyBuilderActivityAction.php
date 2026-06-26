<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lalalili\SurveyCore\Models\Survey;
use Spatie\Activitylog\Models\Activity;

class RecordSurveyBuilderActivityAction
{
    private const LOG_NAME = 'survey_builder';

    public function recordAutosave(Survey $survey, ?Authenticatable $causer = null): Activity
    {
        $existingActivity = $this->queryFor($survey, 'autosaved', $causer)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('created_at')
            ->first();

        if ($existingActivity instanceof Activity) {
            $properties = $existingActivity->properties?->toArray() ?? [];
            $autosaveCount = ((int) ($properties['autosave_count'] ?? 1)) + 1;

            $existingActivity->forceFill([
                'description' => '自動儲存問卷草稿',
                'properties' => [
                    ...$properties,
                    'version' => $survey->version,
                    'autosave_count' => $autosaveCount,
                    'last_saved_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            return $existingActivity->refresh();
        }

        return $this->record($survey, 'autosaved', '自動儲存問卷草稿', $causer, [
            'version' => $survey->version,
            'autosave_count' => 1,
            'last_saved_at' => now()->toIso8601String(),
        ]);
    }

    public function recordPublished(Survey $survey, ?Authenticatable $causer = null): Activity
    {
        return $this->record($survey, 'published', '發布問卷', $causer, [
            'version' => $survey->version,
            'published_at' => $survey->published_at?->toIso8601String(),
        ]);
    }

    public function recordRestoredPublished(Survey $survey, ?Authenticatable $causer = null): Activity
    {
        return $this->record($survey, 'restored_published', '回復至目前發布版本', $causer, [
            'version' => $survey->version,
            'published_at' => $survey->published_at?->toIso8601String(),
            'restored_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(Survey $survey, string $event, string $description, ?Authenticatable $causer, array $properties): Activity
    {
        $logger = activity(self::LOG_NAME)
            ->event($event)
            ->performedOn($survey)
            ->withProperties($properties);

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        /** @var Activity $activity */
        $activity = $logger->log($description);

        return $activity;
    }

    /**
     * @return Builder<Activity>
     */
    private function queryFor(Survey $survey, string $event, ?Authenticatable $causer): Builder
    {
        $query = Activity::query()
            ->inLog(self::LOG_NAME)
            ->forSubject($survey)
            ->forEvent($event);

        if ($causer instanceof Model) {
            $query
                ->where('causer_type', $causer->getMorphClass())
                ->where('causer_id', $causer->getKey());
        } else {
            $query
                ->whereNull('causer_type')
                ->whereNull('causer_id');
        }

        return $query;
    }
}
