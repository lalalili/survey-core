<?php

namespace Lalalili\SurveyCore\Listeners;

use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

class EvaluateSurveyTriggersListener
{
    public function handle(SurveySubmitted $event): void
    {
        SurveyTriggerRule::where('survey_id', $event->survey->id)
            ->where('is_active', true)
            ->get()
            ->each(function (SurveyTriggerRule $rule) use ($event): void {
                RunSurveyTriggerJob::dispatch($rule->id, $event->response->id);
            });
    }
}
