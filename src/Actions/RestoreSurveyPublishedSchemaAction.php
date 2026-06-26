<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;
use Symfony\Component\HttpFoundation\Response;

class RestoreSurveyPublishedSchemaAction
{
    public function __construct(
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SanitizeSurveyBuilderSchemaAction $sanitizeSchema,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
        private readonly SurveyBuilderSurveySettings $surveySettings,
    ) {}

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
            return DB::transaction(function () use ($survey): Survey {
                $schema = $this->validateSchema->execute($survey->published_schema);
                $schema = $this->sanitizeSchema->execute($schema);
                $schema = $this->surveySettings->normalizeSchema($schema);

                $survey->update([
                    ...$this->surveySettings->surveyAttributesFromSchema($schema),
                    'settings_json' => $this->surveySettings->settingsJsonFromSchema($schema, $survey->settings_json),
                    'theme_id' => $schema['theme_id'] ?? null,
                    'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                    'draft_schema' => $schema,
                ]);

                $refreshed = $survey->refresh();
                $this->syncSchemaToFields->execute($refreshed, $schema);

                return $refreshed->refresh();
            });
        } finally {
            $survey->enableLogging();
        }
    }
}
