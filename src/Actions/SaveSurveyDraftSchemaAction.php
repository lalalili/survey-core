<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;

class SaveSurveyDraftSchemaAction
{
    public function __construct(
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SanitizeSurveyBuilderSchemaAction $sanitizeSchema,
        private readonly SyncSurveyResultContextFieldsAction $syncResultContextFields,
        private readonly SurveyBuilderSurveySettings $surveySettings,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(Survey $survey, array $schema): Survey
    {
        $schema = $this->validateSchema->execute($schema);
        $schema = $this->sanitizeSchema->execute($schema);
        $schema = $this->surveySettings->normalizeSchema($schema);
        $schema = $this->syncResultContextFields->execute($schema);

        $survey->disableLogging();

        try {
            $survey->update(['draft_schema' => $schema]);

            return $survey->refresh();
        } finally {
            $survey->enableLogging();
        }
    }
}
