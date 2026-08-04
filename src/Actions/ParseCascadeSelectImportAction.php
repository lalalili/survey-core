<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class ParseCascadeSelectImportAction
{
    /**
     * @return array{levels: list<array{id: string, label: string}>, data: list<array<string, mixed>>}
     */
    public function execute(string $path): array
    {
        $rows = $this->readRows($path);

        if (count($rows) < 2) {
            throw new RuntimeException('檔案沒有可匯入的資料。');
        }

        $headers = array_values(array_filter(
            array_map(fn (mixed $value): string => trim((string) $value), $rows[0]),
            fn (string $value): bool => $value !== '',
        ));

        if ($headers === []) {
            throw new RuntimeException('檔案沒有標題列。');
        }

        $dataRows = array_slice($rows, 1);
        $root = [];

        foreach ($dataRows as $row) {
            $values = array_slice(array_map(fn (mixed $value): string => trim((string) $value), $row), 0, count($headers));
            $values = array_values(array_filter($values, fn (string $value): bool => $value !== ''));

            if ($values === []) {
                continue;
            }

            $root = $this->appendPath($root, $values);
        }

        if ($root === []) {
            throw new RuntimeException('檔案沒有可匯入的資料。');
        }

        return [
            'levels' => array_map(fn (string $label): array => [
                'id' => 'lvl_'.Str::random(8),
                'label' => $label,
            ], $headers),
            'data' => $root,
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_values(array_map(
                    fn (Cell $cell): mixed => $cell->getValue(),
                    $row->getCells(),
                ));
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    private function appendPath(array $nodes, array $values): array
    {
        $label = array_shift($values);

        if ($label === null) {
            return $nodes;
        }

        $index = null;

        foreach ($nodes as $nodeIndex => $node) {
            if ($node['label'] === $label) {
                $index = $nodeIndex;
                break;
            }
        }

        if ($index === null) {
            $nodes[] = [
                'id' => 'nd_'.Str::random(8),
                'label' => $label,
                'children' => [],
            ];
            $index = count($nodes) - 1;
        }

        if ($values === []) {
            return $nodes;
        }

        $nodes[$index]['children'] = $this->appendPath($nodes[$index]['children'] ?? [], $values);

        return $nodes;
    }
}
