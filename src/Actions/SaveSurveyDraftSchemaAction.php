<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Models\Survey;

class SaveSurveyDraftSchemaAction
{
    public function __construct(
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
    ) {
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(Survey $survey, array $schema): Survey
    {
        $schema = $this->validateSchema->execute($schema);

        return DB::transaction(function () use ($survey, $schema): Survey {
            $survey->update([
                'title'        => $schema['title'],
                'settings_json' => $this->surveySettings($schema),
                'theme_id' => $schema['theme_id'] ?? null,
                'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                'draft_schema' => $schema,
            ]);

            $refreshed = $survey->refresh();
            $this->syncSchemaToFields->execute($refreshed, $schema);

            return $refreshed->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function surveySettings(array $schema): ?array
    {
        $settings = $schema['settings'] ?? [];

        if (! empty($schema['thank_you_branches'])) {
            $settings['thank_you_branches'] = $schema['thank_you_branches'];
        }

        return $settings === [] ? null : $settings;
    }
}
