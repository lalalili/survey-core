<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;

class PublishSurveyAction
{
    public function __construct(
        private readonly BuildSurveyBuilderSchemaAction $buildSchema,
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
    ) {
    }

    public function execute(Survey $survey): Survey
    {
        if (! in_array($survey->status, [SurveyStatus::Draft, SurveyStatus::Published, SurveyStatus::Closed])) {
            throw new SurveyNotAvailableException("Only draft, published, or closed surveys can be published. Current status: {$survey->status->value}.");
        }

        return DB::transaction(function () use ($survey): Survey {
            $schema = $this->validateSchema->execute($survey->draft_schema ?? $this->buildSchema->execute($survey));

            if ($survey->status === SurveyStatus::Published && $survey->published_schema === $schema) {
                return $survey->refresh();
            }

            $survey->update([
                'title'            => $schema['title'],
                'settings_json'    => $this->surveySettings($schema),
                'theme_id'         => $schema['theme_id'] ?? null,
                'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                'status'           => SurveyStatus::Published,
                'version'          => ((int) $survey->version) + 1,
                'draft_schema'     => $schema,
                'published_schema' => $schema,
                'published_at'     => now(),
            ]);

            $this->syncSchemaToFields->execute($survey->refresh(), $schema);

            return $survey->refresh();
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
