<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveyClosed;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;

class CloseSurveyAction
{
    public function execute(Survey $survey): Survey
    {
        if ($survey->status !== SurveyStatus::Published) {
            throw new SurveyNotAvailableException("Only published surveys can be closed. Current status: {$survey->status->value}.");
        }

        $survey->update(['status' => SurveyStatus::Closed]);
        $survey->refresh();

        SurveyClosed::dispatch($survey);

        return $survey;
    }
}
