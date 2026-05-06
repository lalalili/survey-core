<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Str;
use Lalalili\SurveyCore\Models\Survey;

class ExportSurveyBuilderSchemaAction
{
    public function __construct(
        private readonly BuildSurveyBuilderSchemaAction $buildSchema,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Survey $survey): array
    {
        return is_array($survey->draft_schema)
            ? $survey->draft_schema
            : $this->buildSchema->execute($survey);
    }

    public function toJson(Survey $survey): string
    {
        return json_encode(
            $this->execute($survey),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function filename(Survey $survey): string
    {
        $slug = Str::slug($survey->title) ?: 'survey';

        return "survey-{$survey->getKey()}-{$slug}.builder.json";
    }
}
