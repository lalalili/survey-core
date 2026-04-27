<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Str;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

class BuildSurveyBuilderSchemaAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Survey $survey): array
    {
        if (is_array($survey->draft_schema)) {
            return $survey->draft_schema;
        }

        $survey->loadMissing('pages', 'fields');
        $survey->loadMissing('calculations');

        $pages = $this->buildPages($survey);

        return [
            'id'      => $survey->id,
            'title'   => $survey->title,
            'status'  => $survey->status->value,
            'version' => $survey->version,
            'settings' => $survey->settings_json ?? ['progress' => ['mode' => 'bar', 'show_estimated_time' => true]],
            'theme_id' => $survey->theme_id,
            'theme_overrides' => $survey->theme_overrides_json ?? [],
            'calculations' => $this->buildCalculations($survey),
            'thank_you_branches' => $survey->settings_json['thank_you_branches'] ?? [],
            'pages'   => $pages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPages(Survey $survey): array
    {
        $sortedPages = $survey->pages->sortBy('sort_order')->values();

        // If normalized pages exist, use them (standard path after builder sync).
        if ($sortedPages->isNotEmpty()) {
            $fieldsByPageId = $survey->fields->groupBy('survey_page_id');

            return $sortedPages->map(fn (SurveyPage $page): array => [
                'id'       => $page->page_key,
                'kind'     => $page->kind->value,
                'title'    => $page->title,
                'welcome_settings' => $page->settings_json['welcome'] ?? null,
                'thank_you_settings' => $page->settings_json['thank_you'] ?? null,
                'jump_rules' => $page->settings_json['jump_rules'] ?? [],
                'elements' => ($fieldsByPageId->get($page->id) ?? collect())
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (SurveyField $field): array => $this->fieldToElement($field))
                    ->all(),
            ])->all();
        }

        // Fallback: build from fields with null survey_page_id (pre-builder surveys).
        $elements = $survey->fields
            ->sortBy('sort_order')
            ->values()
            ->map(fn (SurveyField $field): array => $this->fieldToElement($field))
            ->all();

        return [
            [
                'id'       => 'page_1',
                'kind'     => 'question',
                'title'    => 'Page 1',
                'elements' => $elements,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldToElement(SurveyField $field): array
    {
        return [
            'id'          => 'field_' . $field->id,
            'type'        => $field->type->value,
            'field_key'   => $field->field_key,
            'label'       => $field->label,
            'description' => $field->description ?? '',
            'required'    => (bool) $field->is_required,
            'placeholder' => $field->placeholder,
            'options'     => $this->optionsToBuilderOptions($field),
            'settings'    => array_merge($field->settings_json ?? [], [
                'default_value'    => $field->default_value,
                'validation_rules' => $field->validation_rules ?? [],
            ]),
            'matrix_rows' => $field->settings_json['matrix_rows'] ?? [],
            'matrix_cols' => $field->settings_json['matrix_cols'] ?? [],
            'validation_rules' => $field->validation_rules ?? ($field->settings_json['validation_rules'] ?? []),
            'show_if' => $this->showIfToBuilder($field),
            'show_if_field_key' => $field->show_if_field_key,
            'show_if_value'     => $field->show_if_value,
            'is_hidden'         => (bool) $field->is_hidden,
            'personalized_key'  => $field->personalized_key,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function showIfToBuilder(SurveyField $field): ?array
    {
        $showIf = $field->settings_json['show_if'] ?? null;

        if (is_array($showIf)) {
            return $showIf;
        }

        if (! $field->show_if_field_key) {
            return null;
        }

        return [
            'logic' => 'and',
            'conditions' => [[
                'field_key' => $field->show_if_field_key,
                'op' => 'equals',
                'value' => $field->show_if_value,
            ]],
        ];
    }

    /**
     * @return array<int, array{id: string, label: string, value: string}>
     */
    private function optionsToBuilderOptions(SurveyField $field): array
    {
        if (! $field->type->requiresOptions()) {
            return [];
        }

        $options = $field->options_json ?? [];

        if (array_is_list($options)) {
            return collect($options)
                ->map(function (mixed $option, int $index): array {
                    $entry = [
                        'id'    => (string) data_get($option, 'id', 'opt_' . ($index + 1)),
                        'label' => (string) data_get($option, 'label', data_get($option, 'value', 'Option ' . ($index + 1))),
                        'value' => (string) data_get($option, 'value', data_get($option, 'label', $index)),
                        'capacity' => data_get($option, 'capacity'),
                        'is_hidden' => (bool) data_get($option, 'is_hidden', false),
                    ];

                    $action = data_get($option, 'action');
                    if (is_array($action) && isset($action['type'])) {
                        $entry['action'] = $action;
                    }

                    $scoreDelta = data_get($option, 'score_delta_json');
                    if (is_array($scoreDelta)) {
                        $entry['score_delta_json'] = $scoreDelta;
                    }

                    return $entry;
                })
                ->values()
                ->all();
        }

        return collect($options)
            ->map(fn (mixed $label, mixed $value): array => [
                'id'    => 'opt_' . Str::slug((string) $value, '_'),
                'label' => (string) $label,
                'value' => (string) $value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildCalculations(Survey $survey): array
    {
        return $survey->calculations
            ->map(fn ($calculation): array => [
                'id' => 'calc_'.$calculation->id,
                'key' => $calculation->key,
                'label' => $calculation->label,
                'initial_value' => $calculation->initial_value,
                'output_format' => $calculation->output_format,
                'grade_map_json' => $calculation->grade_map_json ?? [],
            ])
            ->values()
            ->all();
    }
}
