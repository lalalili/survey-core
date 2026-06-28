<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyCalculation;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;
use Lalalili\SurveyCore\Services\Exports\XlsxSurveyExportDriver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportSurveyResponsesAction
{
    public function __construct(
        private readonly SurveyExportManager $exportManager,
    ) {}

    /**
     * 匯出問卷回覆（串流下載）。
     *
     * @param  Collection<int, SurveyResponse>|null  $responses
     */
    public function execute(Survey $survey, ?string $driver = null, ?Collection $responses = null, bool $answersOnly = false): StreamedResponse
    {
        $driver ??= config('survey-core.exports.default_driver', 'csv');
        [$headers, $rows] = $this->buildExportData($survey, $responses, $answersOnly);

        return $this->exportManager->driver($driver)->write($rows, $headers);
    }

    /**
     * 匯出問卷回覆至 Storage 磁碟（非同步用）。
     *
     * @param  Collection<int, SurveyResponse>|null  $responses
     * @param  (\Closure(int $processed, int $total): void)|null  $onProgress  每處理完一個 chunk 回報進度
     */
    public function exportToDisk(Survey $survey, string $disk, string $storagePath, ?Collection $responses = null, bool $answersOnly = false, ?\Closure $onProgress = null): void
    {
        [$headers, $rows] = $this->buildExportData($survey, $responses, $answersOnly, $onProgress);

        $tmpPath = tempnam(sys_get_temp_dir(), 'survey-export-').'.xlsx';

        try {
            app(XlsxSurveyExportDriver::class)->writeToPath($tmpPath, $rows, $headers);
            Storage::disk($disk)->put($storagePath, (string) file_get_contents($tmpPath));
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @param  Collection<int, SurveyResponse>|null  $responses
     * @param  (\Closure(int $processed, int $total): void)|null  $onProgress
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function buildExportData(Survey $survey, ?Collection $responses, bool $answersOnly, ?\Closure $onProgress = null): array
    {
        $survey->loadMissing(['fields', 'calculations']);

        $fields = $survey->fields;
        $calculations = $survey->calculations;

        $fieldLabels = $fields->map(fn (SurveyField $f): string => $f->label)->all();
        $calcLabels = $calculations->map(fn (SurveyCalculation $c): string => $c->label)->all();

        $headers = $answersOnly
            ? array_values($fieldLabels)
            : array_merge(
                ['Response ID', 'Response Number', 'Submitted At', 'IP', 'Completion Status', 'Recipient Name', 'Recipient Email', 'Recipient External ID'],
                $fieldLabels,
                $calcLabels,
            );

        $rows = [];
        $processed = 0;

        $eager = ['answers.field', 'recipient', 'token'];

        if ($responses === null) {
            // 串流載入全部回覆（可達 3 萬筆、答案數十萬）：每 chunk 釋放 Eloquent 模型，
            // 僅累積輕量 row 陣列，避免一次 load 全部 answers 造成 OOM。
            $total = $survey->responses()->count();

            $survey->responses()
                ->with($eager)
                ->chunkById(500, function (Collection $chunk) use (&$rows, &$processed, $fields, $calculations, $answersOnly, $total, $onProgress): void {
                    foreach ($chunk as $response) {
                        $rows[] = $this->mapResponseToRow($response, $fields, $calculations, $answersOnly);
                    }

                    $processed += $chunk->count();

                    if ($onProgress !== null) {
                        $onProgress($processed, $total);
                    }
                });
        } else {
            $responses = $responses
                ->filter(fn (SurveyResponse $response): bool => $response->survey_id === $survey->id)
                ->values();
            $responses->load($eager);
            $total = $responses->count();

            foreach ($responses as $response) {
                $rows[] = $this->mapResponseToRow($response, $fields, $calculations, $answersOnly);
                $processed++;
            }

            if ($onProgress !== null) {
                $onProgress($processed, $total);
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SurveyField>  $fields
     * @param  \Illuminate\Support\Collection<int, SurveyCalculation>  $calculations
     * @return list<mixed>
     */
    private function mapResponseToRow(SurveyResponse $response, $fields, $calculations, bool $answersOnly): array
    {
        $answersByFieldId = $response->answers->keyBy('survey_field_id');

        $row = $answersOnly ? [] : [
            $response->id,
            $response->response_number,
            $response->submitted_at?->toIso8601String(),
            $response->ip,
            $response->completion_status->value,
            $response->recipient?->name,
            $response->recipient?->email,
            $response->recipient?->external_id,
        ];

        foreach ($fields as $field) {
            if ($field->type === SurveyFieldType::FileUpload) {
                $row[] = $this->fileCell($response, $field);

                continue;
            }

            $answer = $answersByFieldId->get($field->id);
            $value = $answer?->getValue();
            $row[] = is_array($value) ? implode(', ', $value) : $value;
        }

        if (! $answersOnly) {
            foreach ($calculations as $calculation) {
                $row[] = $response->calculations_json[$calculation->key] ?? null;
            }
        }

        return $row;
    }

    /**
     * 檔案上傳欄位輸出：優先 Google Drive 連結，否則回退原始檔名。
     */
    private function fileCell(SurveyResponse $response, SurveyField $field): ?string
    {
        $media = $response->getMedia('survey_files')
            ->first(fn ($item): bool => $item->getCustomProperty('survey_field_key') === $field->field_key);

        if ($media === null) {
            return null;
        }

        $driveLink = $media->getCustomProperty('google_drive_link');

        return is_string($driveLink) && $driveLink !== '' ? $driveLink : $media->file_name;
    }
}
