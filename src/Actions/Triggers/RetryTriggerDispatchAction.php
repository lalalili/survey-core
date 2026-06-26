<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;

class RetryTriggerDispatchAction
{
    public function execute(SurveyTriggerDispatch $dispatch): void
    {
        $dispatch->update([
            'status' => TriggerDispatchStatus::Pending,
            'error' => null,
        ]);

        RunSurveyTriggerJob::dispatch($dispatch->survey_trigger_rule_id, $dispatch->survey_response_id);
    }
}
