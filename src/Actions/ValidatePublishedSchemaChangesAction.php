<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Support\SurveyOptionUsageCounter;

class ValidatePublishedSchemaChangesAction
{
    /**
     * @param  array<string, mixed>  $candidateSchema
     */
    public function execute(Survey $survey, array $candidateSchema): void
    {
        if (! is_array($survey->published_schema)) {
            return;
        }

        $publishedElements = $this->elementsById($survey->published_schema);
        $candidateElements = $this->elementsById($candidateSchema);
        $candidateElementsByFieldKey = $this->elementsByFieldKey($candidateSchema);
        $fieldsByKey = $survey->fields()->withCount('answers')->get()->keyBy('field_key');
        $errors = [];

        foreach ($publishedElements as $elementId => $publishedElement) {
            $publishedFieldKey = trim((string) ($publishedElement['field_key'] ?? ''));
            $candidateElement = $candidateElements[$elementId]
                ?? $candidateElementsByFieldKey[$publishedFieldKey]
                ?? null;

            if (! is_array($candidateElement)) {
                continue;
            }

            $fieldKey = $publishedFieldKey;
            $field = $fieldsByKey->get($fieldKey);

            if (! $field instanceof SurveyField || (int) $field->answers_count === 0) {
                continue;
            }

            $label = (string) ($publishedElement['label'] ?? $field->label);
            $answerCount = (int) $field->answers_count;
            $errorKey = 'schema.pages';

            if ($fieldKey !== trim((string) ($candidateElement['field_key'] ?? ''))) {
                $errors[$errorKey][] = "題目「{$label}」已有 {$answerCount} 筆答案，不能修改欄位代碼 field_key。";
            }

            if (($publishedElement['type'] ?? null) !== ($candidateElement['type'] ?? null)) {
                $errors[$errorKey][] = "題目「{$label}」已有 {$answerCount} 筆答案，不能修改題型。";
            }

            $removedUsedValues = $this->removedUsedOptionValues($field, $publishedElement, $candidateElement);
            if ($removedUsedValues !== []) {
                $values = implode('、', $removedUsedValues);
                $errors[$errorKey][] = "題目「{$label}」已有答案使用選項值 {$values}，不能刪除或修改這些 option value。";
            }
        }

        if ($errors !== []) {
            throw new SurveyValidationException($errors, '問卷結構變更會破壞歷史答案。');
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function elementsById(array $schema): array
    {
        $elements = [];

        foreach ($schema['pages'] ?? [] as $page) {
            if (! is_array($page) || ! is_array($page['elements'] ?? null)) {
                continue;
            }

            foreach ($page['elements'] as $element) {
                if (! is_array($element) || ! filled($element['id'] ?? null)) {
                    continue;
                }

                $elements[(string) $element['id']] = $element;
            }
        }

        return $elements;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function elementsByFieldKey(array $schema): array
    {
        $elements = [];

        foreach ($this->elementsById($schema) as $element) {
            $fieldKey = trim((string) ($element['field_key'] ?? ''));

            if ($fieldKey !== '') {
                $elements[$fieldKey] = $element;
            }
        }

        return $elements;
    }

    /**
     * @param  array<string, mixed>  $publishedElement
     * @param  array<string, mixed>  $candidateElement
     * @return list<string>
     */
    private function removedUsedOptionValues(SurveyField $field, array $publishedElement, array $candidateElement): array
    {
        $publishedValues = $this->optionValues($publishedElement);
        $candidateValues = $this->optionValues($candidateElement);
        $removedValues = array_values(array_diff($publishedValues, $candidateValues));

        if ($removedValues === []) {
            return [];
        }

        $usage = SurveyOptionUsageCounter::count($field, $removedValues);

        return array_values(array_filter(
            $removedValues,
            fn (string $value): bool => ($usage[$value] ?? 0) > 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $element
     * @return list<string>
     */
    private function optionValues(array $element): array
    {
        $values = [];

        foreach ($element['options'] ?? [] as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }
}
