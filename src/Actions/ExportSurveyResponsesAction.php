<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCalculation;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;
use Lalalili\SurveyCore\Services\Exports\XlsxSurveyExportDriver;
use RuntimeException;
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
     * @param  list<int>|null  $responseIds  只匯出指定回覆；以串流方式載入，適合大量選取
     */
    public function exportToDisk(Survey $survey, string $disk, string $storagePath, ?Collection $responses = null, bool $answersOnly = false, ?\Closure $onProgress = null, ?array $responseIds = null): void
    {
        [$headers, $rows] = $this->buildExportData($survey, $responses, $answersOnly, $onProgress, $responseIds);

        $tmpPath = tempnam(sys_get_temp_dir(), 'survey-export-').'.xlsx';

        try {
            app(XlsxSurveyExportDriver::class)->writeToPath($tmpPath, $rows, $headers);

            $contents = file_get_contents($tmpPath);

            if ($contents === false) {
                throw new RuntimeException("Unable to read generated export file [{$tmpPath}].");
            }

            if (! Storage::disk($disk)->put($storagePath, $contents)) {
                throw new RuntimeException("Unable to store generated export file [{$storagePath}] on disk [{$disk}].");
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @param  Collection<int, SurveyResponse>|null  $responses
     * @param  (\Closure(int $processed, int $total): void)|null  $onProgress
     * @param  list<int>|null  $responseIds
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function buildExportData(Survey $survey, ?Collection $responses, bool $answersOnly, ?\Closure $onProgress = null, ?array $responseIds = null): array
    {
        $survey->loadMissing(['activeFields', 'calculations']);

        $fields = $this->exportFields($survey, $responses, $responseIds);
        $calculations = $survey->calculations;

        $fieldLabels = $fields->map(fn (array $field): string => $field['label'])->all();
        $calcLabels = $calculations->map(fn (SurveyCalculation $c): string => $c->label)->all();

        $headers = array_values($answersOnly
            ? $fieldLabels
            : array_merge(
                ['Response ID', 'Response Number', 'Submitted At', 'IP', 'Completion Status', 'Recipient Name', 'Recipient Email', 'Recipient External ID'],
                $fieldLabels,
                $calcLabels,
            ));

        $rows = [];
        $processed = 0;

        $eager = ['answers.field', 'recipient', 'token', 'media'];

        if ($responses === null) {
            // 串流載入回覆（可達 3 萬筆、答案數十萬）：每 chunk 釋放 Eloquent 模型，
            // 僅累積輕量 row 陣列，避免一次 load 全部 answers 造成 OOM。
            // 指定 id 時用 whereIntegerInRaw 內嵌整數，避開 SQL Server 2100 個綁定參數上限。
            $query = $survey->responses()->with($eager);

            if ($responseIds !== null) {
                $query->whereIntegerInRaw('survey_responses.id', $responseIds);
            }

            $total = (clone $query)->toBase()->getCountForPagination();

            $query
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
            $responses->loadMissing($eager);
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
     * @param  \Illuminate\Support\Collection<int, array{key: string, label: string, type: string}>  $fields
     * @param  \Illuminate\Support\Collection<int, SurveyCalculation>  $calculations
     * @return list<mixed>
     */
    private function mapResponseToRow(SurveyResponse $response, $fields, $calculations, bool $answersOnly): array
    {
        $answersByFieldKey = $response->answers->keyBy(fn (SurveyAnswer $answer): string => $answer->fieldKey());

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
            if ($field['type'] === SurveyFieldType::FileUpload->value) {
                $row[] = $this->fileCell($response, $field['key']);

                continue;
            }

            $answer = $answersByFieldKey->get($field['key']);
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
    private function fileCell(SurveyResponse $response, string $fieldKey): ?string
    {
        $media = $response->getMedia('survey_files')
            ->first(fn ($item): bool => $item->getCustomProperty('survey_field_key') === $fieldKey);

        if ($media === null) {
            return null;
        }

        $driveLink = $media->getCustomProperty('google_drive_link');

        return is_string($driveLink) && $driveLink !== '' ? $driveLink : $media->file_name;
    }

    /**
     * @param  Collection<int, SurveyResponse>|null  $responses
     * @param  list<int>|null  $responseIds
     * @return \Illuminate\Support\Collection<int, array{key: string, label: string, type: string}>
     */
    private function exportFields(Survey $survey, ?Collection $responses, ?array $responseIds = null): \Illuminate\Support\Collection
    {
        $fields = $survey->activeFields
            ->map(fn (SurveyField $field): array => [
                'key' => $field->field_key,
                'label' => $field->label,
                'type' => $field->type->value,
            ]);

        $historicalAnswers = ($responses !== null
            ? $responses
                ->filter(fn (SurveyResponse $response): bool => $response->survey_id === $survey->id)
                ->loadMissing('answers.field')
                ->flatMap->answers
            : SurveyAnswer::query()
                ->select([
                    'survey_field_id',
                    'snapshot_field_key',
                    'snapshot_field_label',
                    'snapshot_field_type',
                ])
                ->with('field')
                ->whereHas('response', function ($query) use ($survey, $responseIds): void {
                    $query->where('survey_id', $survey->id);

                    if ($responseIds !== null) {
                        $query->whereIntegerInRaw('survey_responses.id', $responseIds);
                    }
                })
                ->distinct()
                ->get())
            ->map(fn (SurveyAnswer $answer): array => [
                'key' => $answer->fieldKey(),
                'label' => $answer->fieldLabel(),
                'type' => $answer->fieldType(),
            ]);

        return $fields
            ->concat($historicalAnswers)
            ->unique('key')
            ->values();
    }
}
