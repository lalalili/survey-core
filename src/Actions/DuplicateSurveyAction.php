<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

class DuplicateSurveyAction
{
    public function execute(Survey $survey): Survey
    {
        return DB::transaction(function () use ($survey) {
            $survey->loadMissing(['pages', 'fields']);

            $clone = $survey->replicate([
                'public_key',
                'status',
                'version',
                'fields_count',
                'recipients_count',
                'responses_count',
            ]);
            $clone->status = SurveyStatus::Draft;
            $clone->title = $survey->title.' (Copy)';
            $clone->version = 1;
            $clone->save();

            // Clone pages first so cloned fields can be re-pointed to the new
            // page ids. The string `page_key` is preserved, so option jump rules
            // (which reference pages by `page_key`, not numeric id) stay valid.
            $pageIdMap = [];
            $survey->pages->each(function (SurveyPage $page) use ($clone, &$pageIdMap): void {
                $clonedPage = $page->replicate();
                $clonedPage->survey_id = $clone->id;
                $clonedPage->save();

                $pageIdMap[$page->id] = $clonedPage->id;
            });

            $survey->fields->each(function (SurveyField $field) use ($clone, $pageIdMap): void {
                $cloned = $field->replicate();
                $cloned->survey_id = $clone->id;
                $cloned->survey_page_id = $field->survey_page_id
                    ? ($pageIdMap[$field->survey_page_id] ?? null)
                    : null;
                $cloned->save();
            });

            return $clone->refresh();
        });
    }
}
