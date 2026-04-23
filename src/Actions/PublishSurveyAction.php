<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;

class PublishSurveyAction
{
    public function execute(Survey $survey): Survey
    {
        if (! in_array($survey->status, [SurveyStatus::Draft, SurveyStatus::Closed])) {
            throw new SurveyNotAvailableException("Only draft or closed surveys can be published. Current status: {$survey->status->value}.");
        }

        $survey->update(['status' => SurveyStatus::Published]);

        return $survey->refresh();
    }
}
