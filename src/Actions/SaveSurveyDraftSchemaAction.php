<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;

class SaveSurveyDraftSchemaAction
{
    public function __construct(
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SanitizeSurveyBuilderSchemaAction $sanitizeSchema,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
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

        return DB::transaction(function () use ($survey, $schema): Survey {
            $survey->update([
                ...$this->surveySettings->surveyAttributesFromSchema($schema),
                'settings_json' => $this->surveySettings->settingsJsonFromSchema($schema),
                'theme_id' => $schema['theme_id'] ?? null,
                'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                'draft_schema' => $schema,
            ]);

            $refreshed = $survey->refresh();
            $this->syncSchemaToFields->execute($refreshed, $schema);

            return $refreshed->refresh();
        });
    }
}
