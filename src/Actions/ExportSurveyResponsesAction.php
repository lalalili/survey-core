<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Database\Eloquent\Collection;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportSurveyResponsesAction
{
    public function __construct(
        private readonly SurveyExportManager $exportManager,
    ) {}

    /**
     * 匯出問卷回覆。
     *
     * 預設匯出該問卷的全部回覆；若提供 $responses，則只匯出該子集
     * （例如列表頁批次勾選的回覆），且子集中的回覆必須皆屬於 $survey。
     *
     * $answersOnly = true 時只輸出各題答案欄，省略 metadata（Response ID、
     * 提交時間、IP、完成狀態、收件人）與計算欄。
     *
     * @param  Collection<int, SurveyResponse>|null  $responses
     */
    public function execute(Survey $survey, ?string $driver = null, ?Collection $responses = null, bool $answersOnly = false): StreamedResponse
    {
        $driver ??= config('survey-core.exports.default_driver', 'csv');

        $survey->loadMissing(['fields', 'calculations']);

        if ($responses === null) {
            $survey->load(['responses.answers.field', 'responses.recipient', 'responses.token']);
            $responses = $survey->responses;
        } else {
            $responses = $responses
                ->filter(fn (SurveyResponse $response): bool => $response->survey_id === $survey->id)
                ->values();
            $responses->load(['answers.field', 'recipient', 'token']);
        }

        $fields = $survey->fields;
        $calculations = $survey->calculations;

        $headers = $answersOnly
            ? $fields->pluck('label')->all()
            : array_merge(
                ['Response ID', 'Submitted At', 'IP', 'Completion Status', 'Recipient Name', 'Recipient Email', 'Recipient External ID'],
                $fields->pluck('label')->all(),
                $calculations->pluck('label')->all(),
            );

        $rows = $responses->map(function (SurveyResponse $response) use ($fields, $calculations, $answersOnly): array {
            $answersByFieldId = $response->answers->keyBy('survey_field_id');

            $row = $answersOnly ? [] : [
                $response->id,
                $response->submitted_at?->toIso8601String(),
                $response->ip,
                $response->completion_status->value,
                $response->recipient?->name,
                $response->recipient?->email,
                $response->recipient?->external_id,
            ];

            foreach ($fields as $field) {
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
        });

        return $this->exportManager->driver($driver)->write($rows->all(), $headers);
    }
}
