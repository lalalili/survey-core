<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Str;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCalculation;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

class SyncSurveyBuilderSchemaToFieldsAction
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(Survey $survey, array $schema): void
    {
        $survey->loadMissing('fields');

        $managedKeys = collect($schema['pages'])
            ->flatMap(fn (array $page): array => $page['elements'])
            ->reject(fn (array $element): bool => SurveyFieldType::from($element['type'])->isContentBlock())
            ->map(fn (array $element): string => (string) $element['field_key'])
            ->filter()
            ->values()
            ->all();

        // Only auto-delete non-hidden fields removed from the schema.
        // Hidden fields not present in the schema are preserved (externally managed).
        $survey->fields()
            ->where('is_hidden', false)
            ->whereNotIn('field_key', $managedKeys)
            ->delete();

        $this->syncCalculations($survey, $schema);

        // Upsert survey_pages — one per schema page, keyed by page.id (= page_key).
        $pageKeyToId = [];

        foreach ($schema['pages'] as $pageIndex => $page) {
            $pageRecord = SurveyPage::updateOrCreate(
                [
                    'survey_id' => $survey->id,
                    'page_key'  => (string) $page['id'],
                ],
                [
                    'title'         => (string) ($page['title'] ?? 'Page ' . ($pageIndex + 1)),
                    'kind'          => (string) ($page['kind'] ?? SurveyPageKind::Question->value),
                    'sort_order'    => $pageIndex + 1,
                    'settings_json' => $this->pageSettings($page),
                ],
            );

            $pageKeyToId[(string) $page['id']] = $pageRecord->id;
        }

        // Delete pages no longer in schema (hidden fields on them get survey_page_id = null via FK).
        $currentPageKeys = array_column($schema['pages'], 'id');
        $survey->pages()->whereNotIn('page_key', $currentPageKeys)->delete();

        // Upsert fields, assigning survey_page_id from the map.
        foreach ($schema['pages'] as $pageIndex => $page) {
            $surveyPageId = $pageKeyToId[(string) $page['id']] ?? null;

            foreach ($page['elements'] as $elementIndex => $element) {
                $type = SurveyFieldType::from($element['type']);

                if ($type->isContentBlock()) {
                    continue;
                }

                $isHidden = (bool) ($element['is_hidden'] ?? false);
                $personalizedKey = $element['personalized_key'] ?? null;

                $legacyShowIf = $this->legacyShowIf($element);

                SurveyField::updateOrCreate(
                    [
                        'survey_id' => $survey->id,
                        'field_key' => $element['field_key'],
                    ],
                    [
                        'type'              => $type,
                        'label'             => $element['label'],
                        'description'       => $element['description'] ?? null,
                        'is_required'       => (bool) $element['required'],
                        'is_hidden'         => $isHidden,
                        'is_personalized'   => $isHidden && ! empty($personalizedKey),
                        'personalized_key'  => $personalizedKey,
                        'placeholder'       => $element['placeholder'] ?? null,
                        'default_value'     => $element['settings']['default_value'] ?? null,
                        'validation_rules'  => $element['validation_rules'] ?? $element['settings']['validation_rules'] ?? null,
                        'settings_json'     => $this->fieldSettings($element),
                        'options_json'      => $this->builderOptionsToFieldOptions($element),
                        'sort_order'        => ($pageIndex * 1000) + $elementIndex + 1,
                        'survey_page_id'    => $surveyPageId,
                        'show_if_field_key' => $legacyShowIf['field_key'],
                        'show_if_value'     => $legacyShowIf['value'],
                    ],
                );
            }
        }
    }

    /**
     * Convert builder options to list-format options_json.
     * List format preserves option ID, label, value, and any jump action.
     *
     * @param  array<string, mixed>  $element
     * @return list<array<string, mixed>>
     */
    private function builderOptionsToFieldOptions(array $element): array
    {
        $options = [];

        foreach ($element['options'] ?? [] as $index => $option) {
            $label = trim((string) ($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $value = (string) ($option['value'] ?? '');
            if ($value === '') {
                $value = Str::slug($label, '_') ?: 'opt_' . ($index + 1);
            }

            $entry = [
                'id'    => (string) ($option['id'] ?? 'opt_' . ($index + 1)),
                'label' => $label,
                'value' => $value,
            ];

            if (($option['capacity'] ?? null) !== null && $option['capacity'] !== '') {
                $entry['capacity'] = max(0, (int) $option['capacity']);
            }

            if ((bool) ($option['is_hidden'] ?? false)) {
                $entry['is_hidden'] = true;
            }

            $action = $option['action'] ?? null;
            if (is_array($action) && isset($action['type']) && $action['type'] !== 'next_page') {
                $normalized = ['type' => $action['type']];
                if ($action['type'] === 'go_to_page' && ! empty($action['target_page_id'])) {
                    $normalized['target_page_id'] = $action['target_page_id'];
                }
                $entry['action'] = $normalized;
            }

            if (isset($option['score_delta_json']) && is_array($option['score_delta_json'])) {
                $entry['score_delta_json'] = $option['score_delta_json'];
            }

            $options[] = $entry;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>|null
     */
    private function fieldSettings(array $element): ?array
    {
        $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
        unset($settings['default_value'], $settings['validation_rules']);

        foreach (['matrix_rows', 'matrix_cols', 'validation_rules', 'show_if'] as $key) {
            if (isset($element[$key])) {
                $settings[$key] = $element[$key];
            }
        }

        $legacyShowIf = $this->legacyShowIf($element);
        if ($legacyShowIf['field_key'] !== null) {
            unset($settings['show_if']);
        }

        return $settings === [] ? null : $settings;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array{field_key: string|null, value: string|null}
     */
    private function legacyShowIf(array $element): array
    {
        $showIf = $element['show_if'] ?? null;

        if (is_array($showIf)) {
            $conditions = collect($showIf['conditions'] ?? [])
                ->filter(fn (mixed $condition): bool => is_array($condition))
                ->values();

            if (
                strtolower((string) ($showIf['logic'] ?? 'and')) === 'and'
                && $conditions->count() === 1
                && ($conditions[0]['op'] ?? 'equals') === 'equals'
                && filled($conditions[0]['field_key'] ?? null)
            ) {
                return [
                    'field_key' => (string) $conditions[0]['field_key'],
                    'value'     => isset($conditions[0]['value']) ? (string) $conditions[0]['value'] : null,
                ];
            }

            return ['field_key' => null, 'value' => null];
        }

        return [
            'field_key' => isset($element['show_if_field_key']) ? (string) $element['show_if_field_key'] : null,
            'value'     => isset($element['show_if_value']) ? (string) $element['show_if_value'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function syncCalculations(Survey $survey, array $schema): void
    {
        $keys = collect($schema['calculations'] ?? [])
            ->pluck('key')
            ->filter()
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();

        $survey->calculations()->whereNotIn('key', $keys)->delete();

        foreach (($schema['calculations'] ?? []) as $calculation) {
            SurveyCalculation::updateOrCreate(
                ['survey_id' => $survey->id, 'key' => (string) $calculation['key']],
                [
                    'label'          => (string) $calculation['label'],
                    'initial_value'  => (int) ($calculation['initial_value'] ?? 0),
                    'output_format'  => (string) ($calculation['output_format'] ?? 'number'),
                    'grade_map_json' => $calculation['grade_map_json'] ?? null,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>|null
     */
    private function pageSettings(array $page): ?array
    {
        $settings = [];

        if (isset($page['welcome_settings']) && is_array($page['welcome_settings'])) {
            $settings['welcome'] = $page['welcome_settings'];
        }

        if (isset($page['thank_you_settings']) && is_array($page['thank_you_settings'])) {
            $settings['thank_you'] = $page['thank_you_settings'];
        }

        if (isset($page['jump_rules']) && is_array($page['jump_rules'])) {
            $settings['jump_rules'] = $page['jump_rules'];
        }

        return $settings === [] ? null : $settings;
    }
}
