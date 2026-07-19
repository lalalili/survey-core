<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('survey_schema_versions')) {
            return;
        }

        DB::table('surveys')
            ->orderBy('id')
            ->chunkById(100, function ($surveys): void {
                foreach ($surveys as $survey) {
                    $hasResponses = DB::table('survey_responses')
                        ->where('survey_id', $survey->id)
                        ->exists();
                    $fields = DB::table('survey_fields')
                        ->where('survey_id', $survey->id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get();

                    $publishedSchema = $this->decodeJson($survey->published_schema ?? null);
                    $isPublished = (string) ($survey->status ?? '') === 'published';

                    if ($publishedSchema === null && ! $isPublished && ! $hasResponses) {
                        continue;
                    }

                    $schema = $publishedSchema ?? $this->buildLegacySchema(
                        (array) $survey,
                        $fields->map(fn (stdClass $field): array => $this->rowToArray($field)),
                    );
                    $version = max(1, (int) ($survey->version ?? 1));
                    $now = now();

                    DB::table('survey_schema_versions')->updateOrInsert(
                        ['survey_id' => $survey->id, 'version' => $version],
                        [
                            'schema_json' => json_encode($schema, JSON_THROW_ON_ERROR),
                            'source' => 'legacy_backfill',
                            'published_at' => $survey->published_at ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );

                    $schemaVersionId = DB::table('survey_schema_versions')
                        ->where('survey_id', $survey->id)
                        ->where('version', $version)
                        ->value('id');

                    if ($schemaVersionId === null) {
                        continue;
                    }

                    $elementsByFieldKey = $this->elementsByFieldKey($schema);

                    foreach ($fields as $field) {
                        $element = $elementsByFieldKey[(string) $field->field_key] ?? null;

                        DB::table('survey_field_versions')->updateOrInsert(
                            ['schema_version_id' => $schemaVersionId, 'field_key' => $field->field_key],
                            [
                                'survey_field_id' => $field->id,
                                'element_id' => (string) ($element['id'] ?? 'legacy_field_'.$field->id),
                                'label' => (string) ($element['label'] ?? $field->label),
                                'type' => (string) ($element['type'] ?? $field->type),
                                'options_json' => $this->encodeJsonValue($element['options'] ?? $field->options_json),
                                'settings_json' => $this->encodeJsonValue($element['settings'] ?? $field->settings_json),
                                'sort_order' => (int) $field->sort_order,
                            ],
                        );
                    }

                    if ($publishedSchema !== null || $isPublished) {
                        DB::table('surveys')->where('id', $survey->id)->update([
                            'published_schema_version_id' => $schemaVersionId,
                        ]);
                    }
                    DB::table('survey_responses')
                        ->where('survey_id', $survey->id)
                        ->whereNull('schema_version_id')
                        ->update(['schema_version_id' => $schemaVersionId]);
                }
            });

        DB::table('survey_answers')
            ->whereNull('snapshot_field_key')
            ->orderBy('id')
            ->chunkById(500, function ($answers): void {
                $fields = DB::table('survey_fields')
                    ->whereIn('id', $answers->pluck('survey_field_id')->unique()->all())
                    ->get()
                    ->keyBy('id');

                foreach ($answers as $answer) {
                    $field = $fields->get($answer->survey_field_id);

                    if ($field === null) {
                        continue;
                    }

                    DB::table('survey_answers')->where('id', $answer->id)->update([
                        'snapshot_field_key' => $field->field_key,
                        'snapshot_field_label' => $field->label,
                        'snapshot_field_type' => $field->type,
                        'snapshot_options_json' => $field->options_json,
                        'snapshot_settings_json' => $field->settings_json,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: new responses may already reference a backfilled version.
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $survey
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function buildLegacySchema(array $survey, $fields): array
    {
        return [
            'id' => 'legacy_survey_'.$survey['id'],
            'title' => (string) $survey['title'],
            'version' => max(1, (int) ($survey['version'] ?? 1)),
            'pages' => [[
                'id' => 'legacy_page_'.$survey['id'],
                'kind' => 'question',
                'title' => 'Legacy questions',
                'elements' => $fields->map(fn (array $field): array => [
                    'id' => 'legacy_field_'.$field['id'],
                    'field_key' => (string) $field['field_key'],
                    'label' => (string) $field['label'],
                    'type' => (string) $field['type'],
                    'options' => $this->decodeJson($field['options_json']) ?? [],
                    'settings' => $this->decodeJson($field['settings_json']) ?? [],
                ])->values()->all(),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function elementsByFieldKey(array $schema): array
    {
        $elements = [];

        foreach ((array) ($schema['pages'] ?? []) as $page) {
            foreach ((array) ($page['elements'] ?? []) as $element) {
                $fieldKey = $element['field_key'] ?? null;

                if (is_string($fieldKey) && $fieldKey !== '') {
                    $elements[$fieldKey] = $element;
                }
            }
        }

        return $elements;
    }

    private function encodeJsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function rowToArray(stdClass $row): array
    {
        return (array) $row;
    }
};
