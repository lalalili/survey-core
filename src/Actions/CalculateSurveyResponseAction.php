<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCalculation;
use Lalalili\SurveyCore\Models\SurveyField;

class CalculateSurveyResponseAction
{
    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function execute(Survey $survey, array $answers): array
    {
        $survey->loadMissing('calculations', 'fields');

        $calculations = $survey->calculations->keyBy('key');

        if ($calculations->isEmpty()) {
            return [];
        }

        $scores = $calculations
            ->mapWithKeys(fn (SurveyCalculation $calculation): array => [
                $calculation->key => (int) $calculation->initial_value,
            ])
            ->all();

        $fieldsByKey = $survey->fields->keyBy('field_key');

        foreach ($answers as $fieldKey => $answer) {
            $field = $fieldsByKey->get($fieldKey);

            if (! $field instanceof SurveyField || empty($field->options_json) || ! array_is_list($field->options_json)) {
                continue;
            }

            $submittedValues = array_map('strval', (array) $answer);

            foreach ($field->options_json as $option) {
                if (! in_array((string) ($option['value'] ?? ''), $submittedValues, true)) {
                    continue;
                }

                foreach (($option['score_delta_json'] ?? []) as $calculationKey => $delta) {
                    if (! array_key_exists($calculationKey, $scores)) {
                        continue;
                    }

                    $scores[$calculationKey] += (int) $delta;
                }
            }
        }

        foreach ($calculations as $key => $calculation) {
            if ($calculation->output_format !== 'grade') {
                continue;
            }

            $scores[$key] = $this->resolveGradeLabel((int) $scores[$key], $calculation->grade_map_json ?? []) ?? $scores[$key];
        }

        return $scores;
    }

    /**
     * @param  array<int, array<string, mixed>>  $gradeMap
     */
    private function resolveGradeLabel(int $score, array $gradeMap): ?string
    {
        foreach ($gradeMap as $grade) {
            $min = array_key_exists('min', $grade) ? (int) $grade['min'] : PHP_INT_MIN;
            $max = array_key_exists('max', $grade) ? (int) $grade['max'] : PHP_INT_MAX;

            if ($score >= $min && $score <= $max) {
                return isset($grade['label']) ? (string) $grade['label'] : null;
            }
        }

        return null;
    }
}
