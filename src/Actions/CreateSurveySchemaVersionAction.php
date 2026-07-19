<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveySchemaVersion;

class CreateSurveySchemaVersionAction
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(Survey $survey, array $schema, string $source = 'publish'): SurveySchemaVersion
    {
        $survey->load('fields');

        $schemaVersion = SurveySchemaVersion::create([
            'survey_id' => $survey->id,
            'version' => $survey->version,
            'schema_json' => $schema,
            'source' => $source,
            'published_at' => $survey->published_at ?? now(),
        ]);

        $elementsByFieldKey = $this->elementsByFieldKey($schema);

        $rows = $survey->fields
            ->filter(fn (SurveyField $field): bool => isset($elementsByFieldKey[$field->field_key]))
            ->map(function (SurveyField $field) use ($schemaVersion, $elementsByFieldKey): array {
                $element = $elementsByFieldKey[$field->field_key] ?? [];

                return [
                    'schema_version_id' => $schemaVersion->id,
                    'survey_field_id' => $field->id,
                    'element_id' => (string) ($element['id'] ?? 'field_'.$field->id),
                    'field_key' => $field->field_key,
                    'label' => $field->label,
                    'type' => $field->type->value,
                    'options_json' => $this->encodeJson($field->options_json),
                    'settings_json' => $this->encodeJson($field->settings_json),
                    'sort_order' => $field->sort_order,
                ];
            })
            ->all();

        if ($rows !== []) {
            $schemaVersion->fieldVersions()->insert($rows);
        }

        return $schemaVersion->load('fieldVersions');
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
                $fieldKey = $element['field_key'] ?? $element['id'] ?? null;

                if (is_string($fieldKey) && $fieldKey !== '') {
                    $elements[$fieldKey] = $element;
                }
            }
        }

        return $elements;
    }

    /** @param array<array-key, mixed>|null $value */
    private function encodeJson(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
