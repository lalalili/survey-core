<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

class DuplicateSurveyAction
{
    public function execute(Survey $survey): Survey
    {
        return DB::transaction(function () use ($survey) {
            $clone = $survey->replicate(['public_key', 'status', 'version']);
            $clone->status = SurveyStatus::Draft;
            $clone->title = $survey->title.' (Copy)';
            $clone->version = 1;
            $clone->save();

            $survey->fields->each(function (SurveyField $field) use ($clone) {
                $cloned = $field->replicate();
                $cloned->survey_id = $clone->id;
                $cloned->save();
            });

            return $clone->refresh();
        });
    }
}
