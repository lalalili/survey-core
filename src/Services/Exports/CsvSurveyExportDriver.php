<?php

namespace Lalalili\SurveyCore\Services\Exports;

use Lalalili\SurveyCore\Contracts\SurveyExportDriver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvSurveyExportDriver implements SurveyExportDriver
{
    /**
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>           $headers
     */
    public function write(iterable $rows, array $headers): StreamedResponse
    {
        $filename = 'survey-responses-' . now()->format('Y-m-d-His') . '.csv';

        return new StreamedResponse(function () use ($rows, $headers) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => $v ?? '', $row));
            }

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
