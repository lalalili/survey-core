<?php

namespace Lalalili\SurveyCore\Actions;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;

class ExportSurveyResponsesAction
{
    public function __construct(
        private readonly SurveyExportManager $exportManager,
    ) {
    }

    public function execute(Survey $survey, ?string $driver = null): StreamedResponse
    {
        $driver ??= config('survey-core.exports.default_driver', 'csv');

        $survey->load(['fields', 'responses.answers.field', 'responses.recipient', 'responses.token']);

        $fields = $survey->fields;

        $headers = array_merge(
            ['Response ID', 'Submitted At', 'IP', 'Completion Status', 'Recipient Name', 'Recipient Email', 'Recipient External ID'],
            $fields->pluck('label')->all(),
        );

        $rows = $survey->responses->map(function ($response) use ($fields) {
            $answersByFieldId = $response->answers->keyBy('survey_field_id');

            $row = [
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

            return $row;
        });

        return $this->exportManager->driver($driver)->write($rows, $headers);
    }
}
