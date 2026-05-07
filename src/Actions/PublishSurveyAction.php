<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;

class PublishSurveyAction
{
    public function __construct(
        private readonly BuildSurveyBuilderSchemaAction $buildSchema,
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
        private readonly SurveyBuilderSurveySettings $surveySettings,
    ) {}

    public function execute(Survey $survey): Survey
    {
        if (! in_array($survey->status, [SurveyStatus::Draft, SurveyStatus::Published, SurveyStatus::Closed])) {
            throw new SurveyNotAvailableException("Only draft, published, or closed surveys can be published. Current status: {$survey->status->value}.");
        }

        return DB::transaction(function () use ($survey): Survey {
            $schema = $this->validateSchema->execute($survey->draft_schema ?? $this->buildSchema->execute($survey));
            $schema = $this->surveySettings->normalizeSchema($schema);
            $publishedSchema = is_array($survey->published_schema)
                ? $this->surveySettings->normalizeSchema($this->validateSchema->execute($survey->published_schema))
                : null;

            if ($survey->status === SurveyStatus::Published && $publishedSchema === $schema) {
                return $survey->refresh();
            }

            $survey->update([
                ...$this->surveySettings->surveyAttributesFromSchema($schema),
                'settings_json' => $this->surveySettings->settingsJsonFromSchema($schema),
                'theme_id' => $schema['theme_id'] ?? null,
                'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                'status' => SurveyStatus::Published,
                'version' => ((int) $survey->version) + 1,
                'draft_schema' => $schema,
                'published_schema' => $schema,
                'published_at' => now(),
            ]);

            $this->syncSchemaToFields->execute($survey->refresh(), $schema);

            return $survey->refresh();
        });
    }
}
