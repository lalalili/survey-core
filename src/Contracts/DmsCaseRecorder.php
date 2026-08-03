<?php

namespace Lalalili\SurveyCore\Contracts;

use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;

interface DmsCaseRecorder
{
    /**
     * 於 DMS 立案成功後，讓宿主應用記錄案件（例如寫入 survey_response_cases）。
     */
    public function record(SurveyResponse $response, SurveyTriggerActionAttempt $attempt): void;
}
