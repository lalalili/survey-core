<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Models\AudienceList;
use Lalalili\SurveyCore\Support\SurveyResultContextFields;

class SyncSurveyResultContextFieldsAction
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function execute(array $schema): array
    {
        $audienceListId = data_get($schema, 'settings.personalization.audience_list_id');
        $audienceList = filled($audienceListId)
            ? AudienceList::query()->whereKey($audienceListId)->first()
            : null;
        $rawColumns = $audienceList?->getAttribute('columns_json');
        $columns = is_array($rawColumns) ? $rawColumns : [];
        $availableColumnKeys = $audienceList === null
            ? []
            : collect($columns)
                ->pluck('key')
                ->filter(fn (mixed $key): bool => filled($key))
                ->map(fn (mixed $key): string => (string) $key)
                ->all();
        $mappings = data_get($schema, 'settings.personalization.result_context_columns', []);
        $mappings = is_array($mappings) ? $mappings : [];
        $reservedFieldKeys = SurveyResultContextFields::fieldKeys();

        foreach ($schema['pages'] ?? [] as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }

            $elements = is_array($page['elements'] ?? null) ? $page['elements'] : [];
            $schema['pages'][$pageIndex]['elements'] = array_values(array_filter(
                $elements,
                fn (mixed $element): bool => ! is_array($element)
                    || ! in_array((string) ($element['field_key'] ?? ''), $reservedFieldKeys, true),
            ));
        }

        if ($audienceList === null) {
            return $schema;
        }

        $targetPageIndex = $this->targetPageIndex($schema);
        if ($targetPageIndex === null) {
            return $schema;
        }

        foreach (SurveyResultContextFields::DEFINITIONS as $semantic => $definition) {
            $personalizedKey = trim((string) ($mappings[$semantic] ?? ''));

            if ($personalizedKey === '' || ! in_array($personalizedKey, $availableColumnKeys, true)) {
                continue;
            }

            $schema['pages'][$targetPageIndex]['elements'][] = [
                'id' => 'field_'.$definition['field_key'],
                'type' => 'short_text',
                'field_key' => $definition['field_key'],
                'label' => $definition['label'],
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'options' => [],
                'settings' => [],
                'is_hidden' => true,
                'personalized_key' => $personalizedKey,
            ];
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function targetPageIndex(array $schema): ?int
    {
        foreach ($schema['pages'] ?? [] as $pageIndex => $page) {
            if (is_array($page) && ($page['kind'] ?? 'question') === 'question') {
                return $pageIndex;
            }
        }

        return isset($schema['pages'][0]) ? 0 : null;
    }
}
