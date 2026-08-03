<?php

namespace Lalalili\SurveyCore\Support;

use Lalalili\SurveyCore\Contracts\DmsCaseRecorder;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;

final class NullDmsCaseRecorder implements DmsCaseRecorder
{
    public function record(SurveyResponse $response, SurveyTriggerActionAttempt $attempt): void
    {
        //
    }
}
