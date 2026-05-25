<?php

namespace Lalalili\SurveyCore\Services\Exports;

use Lalalili\SurveyCore\Contracts\SurveyExportDriver;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XlsxSurveyExportDriver implements SurveyExportDriver
{
    /**
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  list<string>  $headers
     */
    public function write(iterable $rows, array $headers): StreamedResponse
    {
        $filename = 'survey-responses-'.now()->format('Y-m-d-His').'.xlsx';

        return new StreamedResponse(function () use ($rows, $headers) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues($headers));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_values(array_map(
                    fn (mixed $value): bool|\DateInterval|\DateTimeInterface|float|int|string|null => $this->normalizeCellValue($value),
                    $row,
                ))));
            }

            $writer->close();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function normalizeCellValue(mixed $value): bool|\DateInterval|\DateTimeInterface|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value instanceof \DateInterval || $value instanceof \DateTimeInterface) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
