<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use JsonException;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

class ImportSurveyBuilderSchemaAction
{
    public function __construct(
        private readonly SaveSurveyDraftSchemaAction $saveSchema,
        private readonly PublishSurveyAction $publishSurvey,
    ) {
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(array $schema, ?string $title = null, bool $publish = false): Survey
    {
        if (filled($title)) {
            $schema['title'] = $title;
        }

        return DB::transaction(function () use ($schema, $publish): Survey {
            $survey = Survey::create([
                'title'  => (string) ($schema['title'] ?? '匯入問卷'),
                'status' => SurveyStatus::Draft,
            ]);

            $survey = $this->saveSchema->execute($survey, $schema);

            if ($publish) {
                $survey = $this->publishSurvey->execute($survey);
            }

            return $survey->refresh();
        });
    }

    /**
     * @throws JsonException
     */
    public function fromJson(string $json, ?string $title = null, bool $publish = false): Survey
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $this->execute($schema, $title, $publish);
    }
}
