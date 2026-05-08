<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderTemplateRegistry;

class CreateSurveyFromBuilderTemplateAction
{
    public function __construct(
        private readonly SaveSurveyDraftSchemaAction $saveDraftSchema,
        private readonly SurveyBuilderTemplateRegistry $templates,
    ) {}

    public function execute(string $templateSlug): Survey
    {
        $template = $this->templates->find($templateSlug);

        if ($template === null) {
            throw new \InvalidArgumentException("Survey template [{$templateSlug}] does not exist.");
        }

        return DB::transaction(function () use ($template): Survey {
            $survey = Survey::create([
                'title' => $template['name'],
                'status' => SurveyStatus::Draft,
            ]);

            $schema = $template['schema'];
            $schema['id'] = $survey->id;
            $schema['version'] = $survey->version;

            return $this->saveDraftSchema->execute($survey, $schema);
        });
    }
}
