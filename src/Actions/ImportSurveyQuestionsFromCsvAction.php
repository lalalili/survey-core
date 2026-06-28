<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Str;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\FieldKeyGenerator;

/**
 * Append questions to a survey's builder draft schema from a simple CSV.
 *
 * Expected header columns (case-insensitive): type, label, required, options,
 * description. `options` is a pipe (`|`) separated list used by choice types.
 */
class ImportSurveyQuestionsFromCsvAction
{
    /** @var list<string> */
    private const SUPPORTED_TYPES = [
        'single_choice', 'multiple_choice', 'select',
        'short_text', 'long_text', 'number',
        'rating', 'nps', 'linear_scale', 'date', 'time',
    ];

    /** @var list<string> */
    private const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'select'];

    public function __construct(
        private readonly BuildSurveyBuilderSchemaAction $buildSchema,
        private readonly SaveSurveyDraftSchemaAction $saveDraftSchema,
    ) {}

    /**
     * @return int number of questions imported
     */
    public function execute(Survey $survey, string $csv): int
    {
        $rows = $this->parseCsv($csv);

        if ($rows === []) {
            return 0;
        }

        $elements = [];

        foreach ($rows as $row) {
            $element = $this->rowToElement($row);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        if ($elements === []) {
            return 0;
        }

        $schema = $this->buildSchema->execute($survey);
        $schema['pages'] = $this->appendToQuestionPage($schema['pages'] ?? [], $elements);

        $this->saveDraftSchema->execute($survey, $schema);

        return count($elements);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $header = array_map(
            fn (?string $column): string => Str::lower(trim((string) $column)),
            str_getcsv((string) array_shift($lines)),
        );

        $rows = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line);
            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function rowToElement(array $row): ?array
    {
        $type = Str::lower($row['type'] ?? '');
        $label = trim($row['label'] ?? '');

        if ($label === '' || ! in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        $options = [];

        if (in_array($type, self::CHOICE_TYPES, true)) {
            foreach (array_filter(array_map('trim', explode('|', $row['options'] ?? ''))) as $optionLabel) {
                $options[] = [
                    'id' => 'opt_'.Str::lower(Str::random(8)),
                    'label' => $optionLabel,
                    'value' => Str::lower(Str::random(10)),
                ];
            }

            // 選擇題至少需要一個選項，否則略過該題。
            if ($options === []) {
                return null;
            }
        }

        return [
            'id' => 'q_'.Str::lower(Str::random(8)),
            'type' => $type,
            'field_key' => FieldKeyGenerator::generate($label),
            'label' => $label,
            'description' => trim($row['description'] ?? ''),
            'required' => $this->toBool($row['required'] ?? ''),
            'placeholder' => null,
            'options' => $options,
            'settings' => [],
        ];
    }

    /**
     * Append elements to the last question page, creating one when none exist.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    private function appendToQuestionPage(array $pages, array $elements): array
    {
        $targetIndex = null;

        foreach ($pages as $index => $page) {
            if (($page['kind'] ?? 'question') === 'question') {
                $targetIndex = $index;
            }
        }

        if ($targetIndex === null) {
            $pages[] = [
                'id' => 'page_'.Str::lower(Str::random(8)),
                'title' => '匯入題目',
                'kind' => 'question',
                'elements' => $elements,
            ];

            return $pages;
        }

        $existing = is_array($pages[$targetIndex]['elements'] ?? null) ? $pages[$targetIndex]['elements'] : [];
        $pages[$targetIndex]['elements'] = array_merge($existing, $elements);

        return $pages;
    }

    private function toBool(string $value): bool
    {
        return in_array(Str::lower(trim($value)), ['1', 'true', 'yes', 'y', '是', '必填'], true);
    }
}
