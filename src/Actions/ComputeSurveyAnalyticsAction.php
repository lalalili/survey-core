<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Collection;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;

class ComputeSurveyAnalyticsAction
{
    /**
     * @return array{
     *     totals: array{responses: int, started: int, submitted: int, completion_rate: float},
     *     daily: list<array{date: string, started: int, submitted: int}>,
     *     collectors: list<array{collector_id: int, name: string, type: string, slug: string, started: int, submitted: int, completion_rate: float}>,
     *     questions: list<array<string, mixed>>
     * }
     */
    public function execute(Survey $survey, ?int $collectorId = null): array
    {
        $survey->loadMissing(['fields', 'collectors']);

        $submittedResponses = SurveyResponse::query()
            ->with('answers')
            ->where('survey_id', $survey->id)
            ->where('is_test', false)
            ->whereNotNull('submitted_at')
            ->when($collectorId !== null, fn ($query) => $query->where('survey_collector_id', $collectorId))
            ->get();

        $events = SurveyResponseEvent::query()
            ->where('survey_id', $survey->id)
            ->when($collectorId !== null, fn ($query) => $query->where('survey_collector_id', $collectorId))
            ->get();

        $startedCount = $events->where('event', 'started')->count();
        $submittedCount = $submittedResponses->count();

        return [
            'totals' => [
                'responses' => $submittedCount,
                'started' => $startedCount,
                'submitted' => $submittedCount,
                'completion_rate' => $this->rate($submittedCount, max($startedCount, $submittedCount)),
            ],
            'daily' => $this->dailyTrend($events, $submittedResponses),
            'collectors' => $this->collectorPerformance($survey->collectors, $events, $submittedResponses),
            'questions' => $this->questionStats($survey->fields, $submittedResponses),
        ];
    }

    /**
     * @param  Collection<int, SurveyResponseEvent>  $events
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array{date: string, started: int, submitted: int}>
     */
    private function dailyTrend(Collection $events, Collection $responses): array
    {
        $eventDates = $events
            ->map(fn (SurveyResponseEvent $event): string => $event->occurred_at->toDateString())
            ->all();
        $responseDates = $responses
            ->map(fn (SurveyResponse $response): ?string => $this->responseDateString($response))
            ->filter(fn (?string $date): bool => $date !== null)
            ->values()
            ->all();

        $dates = collect($eventDates)
            ->merge($responseDates)
            ->unique()
            ->sort()
            ->values();

        return array_values($dates
            ->map(fn (string $date): array => [
                'date' => $date,
                'started' => $events->filter(fn (SurveyResponseEvent $event): bool => $event->event === 'started' && $event->occurred_at->toDateString() === $date)->count(),
                'submitted' => $responses->filter(fn (SurveyResponse $response): bool => $this->responseDateString($response) === $date)->count(),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, SurveyCollector>  $collectors
     * @param  Collection<int, SurveyResponseEvent>  $events
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array{collector_id: int, name: string, type: string, slug: string, started: int, submitted: int, completion_rate: float}>
     */
    private function collectorPerformance(Collection $collectors, Collection $events, Collection $responses): array
    {
        return array_values($collectors
            ->map(function (SurveyCollector $collector) use ($events, $responses): array {
                $started = $events
                    ->where('survey_collector_id', $collector->id)
                    ->where('event', 'started')
                    ->count();
                $submitted = $responses
                    ->where('survey_collector_id', $collector->id)
                    ->count();

                return [
                    'collector_id' => $collector->id,
                    'name' => $collector->name,
                    'type' => $collector->type,
                    'slug' => $collector->slug,
                    'started' => $started,
                    'submitted' => $submitted,
                    'completion_rate' => $this->rate($submitted, max($started, $submitted)),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function questionStats(Collection $fields, Collection $responses): array
    {
        return array_values($fields
            ->reject(fn (SurveyField $field): bool => $field->is_hidden || $field->type->isContentBlock())
            ->map(fn (SurveyField $field): array => $this->fieldStats($field, $responses, $fields))
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyResponse>  $responses
     * @param  Collection<int, SurveyField>  $fields
     * @return array<string, mixed>
     */
    private function fieldStats(SurveyField $field, Collection $responses, Collection $fields): array
    {
        $answers = $responses
            ->flatMap->answers
            ->filter(fn (SurveyAnswer $answer): bool => $answer->survey_field_id === $field->id)
            ->values();

        $base = [
            'field_id' => $field->id,
            'field_key' => $field->field_key,
            'label' => $field->label,
            'type' => $field->type->value,
            'answered' => $answers->count(),
        ];

        return match ($field->type) {
            SurveyFieldType::SingleChoice,
            SurveyFieldType::Select,
            SurveyFieldType::MultipleChoice => array_merge($base, [
                'distribution' => $this->optionDistribution($field, $answers),
            ]),
            SurveyFieldType::Rating,
            SurveyFieldType::Nps,
            SurveyFieldType::LinearScale => array_merge($base, [
                'average' => $this->average($answers),
                'distribution' => $this->numericDistribution($answers),
            ]),
            SurveyFieldType::Number => array_merge($base, [
                'average' => $this->average($answers),
                'min' => $this->numericMin($answers),
                'max' => $this->numericMax($answers),
            ]),
            SurveyFieldType::MatrixSingle,
            SurveyFieldType::MatrixMulti => array_merge($base, [
                'matrix' => $this->matrixDistribution($field, $answers),
            ]),
            SurveyFieldType::SelectionBased => array_merge($base, [
                'source_field_key' => is_string($field->settings_json['source_field_key'] ?? null)
                    ? $field->settings_json['source_field_key']
                    : null,
                'distribution' => $this->selectionBasedDistribution($field, $answers, $fields),
            ]),
            SurveyFieldType::Ranking => array_merge($base, [
                'ranking' => $this->rankingStats($field, $answers),
            ]),
            SurveyFieldType::ConstantSum => array_merge($base, [
                'constant_sum' => $this->constantSumStats($field, $answers),
            ]),
            SurveyFieldType::ShortText,
            SurveyFieldType::LongText => array_merge($base, [
                'sample' => $this->textSample($answers),
            ]),
            default => $base,
        };
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, label: string, count: int}>
     */
    private function optionDistribution(SurveyField $field, Collection $answers): array
    {
        $counts = [];

        foreach ($answers as $answer) {
            foreach ($this->answerValues($answer) as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return array_values(collect($field->normalizedOptions())
            ->map(fn (array $option): array => [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
                'count' => $counts[(string) $option['value']] ?? 0,
            ])
            ->values()
            ->all());
    }

    /**
     * Distribution for a selection_based (重複核選題) field: the answer values
     * are option values carried over from its source question, so labels are
     * resolved against the source field's options.
     *
     * @param  Collection<int, SurveyAnswer>  $answers
     * @param  Collection<int, SurveyField>  $fields
     * @return list<array{value: string, label: string, count: int}>
     */
    private function selectionBasedDistribution(SurveyField $field, Collection $answers, Collection $fields): array
    {
        $sourceKey = $field->settings_json['source_field_key'] ?? null;
        $source = is_string($sourceKey) ? $fields->firstWhere('field_key', $sourceKey) : null;
        $options = $source instanceof SurveyField ? $source->normalizedOptions() : [];

        $counts = [];

        foreach ($answers as $answer) {
            foreach ($this->answerValues($answer) as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return array_values(collect($options)
            ->map(fn (array $option): array => [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
                'count' => $counts[(string) $option['value']] ?? 0,
            ])
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, count: int}>
     */
    private function numericDistribution(Collection $answers): array
    {
        return array_values($answers
            ->map(fn (SurveyAnswer $answer): ?string => $answer->answer_text !== null ? (string) $answer->answer_text : null)
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $value): array => ['value' => $value, 'count' => $count])
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     */
    private function average(Collection $answers): ?float
    {
        $values = $answers
            ->map(fn (SurveyAnswer $answer): ?float => is_numeric($answer->answer_text) ? (float) $answer->answer_text : null)
            ->filter(fn (?float $value): bool => $value !== null)
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(), 2);
    }

    /**
     * @return list<string>
     */
    private function answerValues(SurveyAnswer $answer): array
    {
        if (is_array($answer->answer_json)) {
            return array_values(array_map('strval', $answer->answer_json));
        }

        return $answer->answer_text !== null ? [(string) $answer->answer_text] : [];
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     */
    private function numericMin(Collection $answers): ?float
    {
        $values = $answers
            ->map(fn (SurveyAnswer $a): ?float => is_numeric($a->answer_text) ? (float) $a->answer_text : null)
            ->filter()
            ->values();

        return $values->isEmpty() ? null : round((float) $values->min(), 2);
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     */
    private function numericMax(Collection $answers): ?float
    {
        $values = $answers
            ->map(fn (SurveyAnswer $a): ?float => is_numeric($a->answer_text) ? (float) $a->answer_text : null)
            ->filter()
            ->values();

        return $values->isEmpty() ? null : round((float) $values->max(), 2);
    }

    /**
     * 矩陣題：回傳 rows × cols 的計數分布。
     * answer_json 格式：{"row_key": "col_key"} (single) 或 {"row_key": ["col_key1", "col_key2"]} (multi)
     *
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return array{rows: array<string, string>, cols: array<string, string>, counts: array<string, array<string, int>>}
     */
    private function matrixDistribution(SurveyField $field, Collection $answers): array
    {
        $options = collect($field->normalizedOptions());
        $rows = $options->where('is_row', true)->pluck('label', 'value')->all();
        $cols = $options->where('is_row', false)->pluck('label', 'value')->all();

        if (empty($rows) || empty($cols)) {
            $rows = $options->take((int) ceil($options->count() / 2))->pluck('label', 'value')->all();
            $cols = $options->skip((int) ceil($options->count() / 2))->pluck('label', 'value')->all();
        }

        $counts = [];

        foreach ($answers as $answer) {
            if (! is_array($answer->answer_json)) {
                continue;
            }

            foreach ($answer->answer_json as $rowKey => $colValue) {
                $colKeys = is_array($colValue) ? $colValue : [$colValue];

                foreach ($colKeys as $colKey) {
                    $counts[(string) $rowKey][(string) $colKey] = ($counts[(string) $rowKey][(string) $colKey] ?? 0) + 1;
                }
            }
        }

        return [
            'rows' => $rows,
            'cols' => $cols,
            'counts' => $counts,
        ];
    }

    /**
     * 排序題：計算每個選項的平均排名（越小越靠前）。
     * answer_json 格式：["value1", "value2", "value3"]（第 0 個為第 1 名）
     *
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, label: string, avg_rank: float|null, count: int}>
     */
    private function rankingStats(SurveyField $field, Collection $answers): array
    {
        $rankSums = [];
        $rankCounts = [];

        foreach ($answers as $answer) {
            if (! is_array($answer->answer_json)) {
                continue;
            }

            foreach (array_values($answer->answer_json) as $position => $value) {
                $key = (string) $value;
                $rankSums[$key] = ($rankSums[$key] ?? 0) + ($position + 1);
                $rankCounts[$key] = ($rankCounts[$key] ?? 0) + 1;
            }
        }

        return array_values(collect($field->normalizedOptions())
            ->map(function (array $option) use ($rankSums, $rankCounts): array {
                $key = (string) $option['value'];
                $count = $rankCounts[$key] ?? 0;

                return [
                    'value' => $key,
                    'label' => (string) $option['label'],
                    'avg_rank' => $count > 0 ? round($rankSums[$key] / $count, 2) : null,
                    'count' => $count,
                ];
            })
            ->sortBy('avg_rank')
            ->values()
            ->all());
    }

    /**
     * 總計題：計算每個子項的平均值。
     * answer_json 格式：{"item_key": amount}
     *
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, label: string, avg: float|null}>
     */
    private function constantSumStats(SurveyField $field, Collection $answers): array
    {
        $sums = [];
        $counts = [];

        foreach ($answers as $answer) {
            if (! is_array($answer->answer_json)) {
                continue;
            }

            foreach ($answer->answer_json as $key => $amount) {
                if (is_numeric($amount)) {
                    $sums[(string) $key] = ($sums[(string) $key] ?? 0) + (float) $amount;
                    $counts[(string) $key] = ($counts[(string) $key] ?? 0) + 1;
                }
            }
        }

        return array_values(collect($field->normalizedOptions())
            ->map(function (array $option) use ($sums, $counts): array {
                $key = (string) $option['value'];
                $count = $counts[$key] ?? 0;

                return [
                    'value' => $key,
                    'label' => (string) $option['label'],
                    'avg' => $count > 0 ? round($sums[$key] / $count, 2) : null,
                ];
            })
            ->values()
            ->all());
    }

    /**
     * 文字題：回傳最多 10 筆非空樣本答案。
     *
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<string>
     */
    private function textSample(Collection $answers): array
    {
        return array_values($answers
            ->map(fn (SurveyAnswer $a): ?string => filled($a->answer_text) ? (string) $a->answer_text : null)
            ->filter()
            ->take(10)
            ->values()
            ->all());
    }

    private function rate(int $count, int $total): float
    {
        if ($total < 1) {
            return 0.0;
        }

        return round(($count / $total) * 100, 2);
    }

    private function responseDateString(SurveyResponse $response): ?string
    {
        return $response->submitted_at?->toDateString()
            ?? $response->created_at?->toDateString();
    }
}
