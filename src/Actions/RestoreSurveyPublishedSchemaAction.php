<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;
use Symfony\Component\HttpFoundation\Response;

class RestoreSurveyPublishedSchemaAction
{
    public function __construct(
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SanitizeSurveyBuilderSchemaAction $sanitizeSchema,
        private readonly SyncSurveyResultContextFieldsAction $syncResultContextFields,
        private readonly SurveyBuilderSurveySettings $surveySettings,
    ) {
    }

    public function execute(Survey $survey): Survey
    {
        if (! is_array($survey->published_schema)) {
            throw new SurveyNotAvailableException(
                'This survey does not have a published version to restore.',
                Response::HTTP_CONFLICT,
            );
        }

        $survey->disableLogging();

        try {
            $schema = $this->validateSchema->execute($survey->published_schema);
            $schema = $this->sanitizeSchema->execute($schema);
            $schema = $this->surveySettings->normalizeSchema($schema);
            $schema = $this->syncResultContextFields->execute($schema);

            $survey->update(['draft_schema' => $schema]);

            return $survey->refresh();
        } finally {
            $survey->enableLogging();
        }
    }
}
