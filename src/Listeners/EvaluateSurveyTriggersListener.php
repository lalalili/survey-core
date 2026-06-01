<?php

namespace Lalalili\SurveyCore\Listeners;

use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

class EvaluateSurveyTriggersListener
{
    public function handle(SurveySubmitted $event): void
    {
        // 測試回覆不觸發正式自動化動作（coupon / DMS 通報等）
        if ($event->response->is_test) {
            return;
        }

        SurveyTriggerRule::where('survey_id', $event->survey->id)
            ->where('is_active', true)
            ->get()
            ->each(function (SurveyTriggerRule $rule) use ($event): void {
                RunSurveyTriggerJob::dispatch($rule->id, $event->response->id);
            });
    }
}
