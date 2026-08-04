<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;
use Lalalili\SurveyCore\Support\SurveyReportCacheRevision;
use Symfony\Component\HttpFoundation\Response;

class PublishSurveyAction
{
    public function __construct(
        private readonly BuildSurveyBuilderSchemaAction $buildSchema,
        private readonly ValidateSurveyBuilderSchemaAction $validateSchema,
        private readonly SanitizeSurveyBuilderSchemaAction $sanitizeSchema,
        private readonly SyncSurveyResultContextFieldsAction $syncResultContextFields,
        private readonly ValidateSurveyResultContextForPublishAction $validateResultContext,
        private readonly ValidatePublishedSchemaChangesAction $validatePublishedSchemaChanges,
        private readonly SyncSurveyBuilderSchemaToFieldsAction $syncSchemaToFields,
        private readonly CreateSurveySchemaVersionAction $createSchemaVersion,
        private readonly SurveyBuilderSurveySettings $surveySettings,
        private readonly SurveyReportCacheRevision $reportCacheRevision,
    ) {
    }

    public function execute(Survey $survey): Survey
    {
        if (! in_array($survey->status, [SurveyStatus::Draft, SurveyStatus::Published, SurveyStatus::Closed])) {
            throw new SurveyNotAvailableException("Only draft, published, or closed surveys can be published. Current status: {$survey->status->value}.");
        }

        $survey->disableLogging();

        try {
            return DB::transaction(function () use ($survey): Survey {
                $schema = $this->validateSchema->execute($survey->draft_schema ?? $this->buildSchema->execute($survey));
                $schema = $this->sanitizeSchema->execute($schema);
                $schema = $this->surveySettings->normalizeSchema($schema);
                $this->validateResultContext->execute($schema);
                $schema = $this->syncResultContextFields->execute($schema);
                $this->validatePublishedSchemaChanges->execute($survey, $schema);

                // 含檔案上傳題的問卷必須先綁定 Google Drive 才能發佈。
                if ($survey->google_drive_account_id === null && $this->schemaHasFileUpload($schema)) {
                    throw new SurveyNotAvailableException(
                        '此問卷包含檔案上傳題，請先於問卷列表「連結 Google Drive」後再發佈。',
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }
                $publishedSchema = is_array($survey->published_schema)
                    ? $this->surveySettings->normalizeSchema($this->sanitizeSchema->execute($this->validateSchema->execute($survey->published_schema)))
                    : null;

                if ($survey->status === SurveyStatus::Published && $publishedSchema === $schema) {
                    return $survey->refresh();
                }

                $survey->update([
                    ...$this->surveySettings->surveyAttributesFromSchema($schema),
                    'settings_json' => $this->surveySettings->settingsJsonFromSchema($schema, $survey->settings_json),
                    'theme_id' => $schema['theme_id'] ?? null,
                    'theme_overrides_json' => $schema['theme_overrides'] ?? null,
                    'version' => ((int) $survey->version) + 1,
                    'draft_schema' => $schema,
                    'published_schema' => $schema,
                    'published_at' => now(),
                ]);

                $this->syncSchemaToFields->execute($survey->refresh(), $schema);

                $survey->refresh();
                $schemaVersion = $this->createSchemaVersion->execute($survey, $schema);

                // status 與 published_schema_version_id 一併寫入：問卷不會出現
                // 「已發佈但沒有已發佈 schema 版本」的中間狀態（資料庫層對此有 CHECK 約束）。
                $survey->update([
                    'status' => SurveyStatus::Published,
                    'published_schema_version_id' => $schemaVersion->id,
                ]);
                $this->reportCacheRevision->bump();

                return $survey->refresh();
            });
        } finally {
            $survey->enableLogging();
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaHasFileUpload(array $schema): bool
    {
        foreach (($schema['pages'] ?? []) as $page) {
            foreach (($page['elements'] ?? []) as $element) {
                if (($element['type'] ?? null) === SurveyFieldType::FileUpload->value) {
                    return true;
                }
            }
        }

        return false;
    }
}
