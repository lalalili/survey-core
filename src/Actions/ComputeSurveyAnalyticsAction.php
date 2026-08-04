<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;

class ComputeSurveyAnalyticsAction
{
    /**
     * Below this many valid respondents an NPS score swings too much to be
     * read as a signal, so it is flagged as low sample in the report.
     */
    private const LOW_SAMPLE_THRESHOLD = 30;

    /**
     * @return array{
     *     totals: array{responses: int, started: int, submitted: int, completion_rate: float},
     *     daily: list<array{date: string, started: int, submitted: int}>,
     *     trend: array{granularity: 'day'|'week'|'month', label: string, rows: list<array{label: string, started: int, submitted: int}>},
     *     collectors: list<array{collector_id: int, name: string, type: string, slug: string, started: int, submitted: int, completion_rate: float}>,
     *     questions: list<array<string, mixed>>
     * }
     */
    public function execute(Survey $survey, ?int $collectorId = null): array
    {
        $survey->loadMissing(['activeFields', 'collectors', 'pages']);

        $submittedResponses = SurveyResponse::query()
            ->with([
                'answers' => fn ($query) => $query->orderBy('id'),
                'answers.field',
            ])
            ->where('survey_id', $survey->id)
            ->reportable()
            ->when($collectorId !== null, fn ($query) => $query->where('survey_collector_id', $collectorId))
            ->orderBy('id')
            ->get();

        $submittedResponses->each(function (SurveyResponse $response): void {
            $response->answers->each->setRelation('response', $response);
        });

        $events = SurveyResponseEvent::query()
            ->where('survey_id', $survey->id)
            ->when($collectorId !== null, fn ($query) => $query->where('survey_collector_id', $collectorId))
            ->get();

        $startedCount = $events->where('event', 'started')->count();
        $submittedCount = $submittedResponses->count();

        $daily = $this->dailyTrend($events, $submittedResponses);

        return [
            'totals' => [
                'responses' => $submittedCount,
                'started' => $startedCount,
                'submitted' => $submittedCount,
                'completion_rate' => $this->rate($submittedCount, max($startedCount, $submittedCount)),
            ],
            'daily' => $daily,
            'trend' => $this->summarizeResponseTrend($daily),
            'collectors' => $this->collectorPerformance($survey->collectors, $events, $submittedResponses),
            'questions' => $this->questionStats(
                $this->analyticsFields($survey->activeFields, $submittedResponses),
                $submittedResponses,
            ),
            'funnel' => $this->funnelStats($survey, $events, $startedCount, $submittedCount),
        ];
    }

    /**
     * 依頁面順序統計每頁的 page_viewed 事件數，呈現填答流程的逐頁流失。
     * 以「開始」為漏斗首階、「送出」為末階；頁面步驟間的落差即 drop-off。
     * 註：匿名部分作答的事件未必帶 survey_response_id，故以事件數（頁面瀏覽量）
     * 而非不重複人數計算。
     *
     * @param  Collection<int, SurveyResponseEvent>  $events
     * @return array{steps: list<array{key: string, label: string, count: int}>}
     */
    private function funnelStats(Survey $survey, Collection $events, int $startedCount, int $submittedCount): array
    {
        $pageViews = $events->where('event', 'page_viewed');

        $pageSteps = $survey->pages
            ->sortBy('sort_order')
            ->values()
            ->map(fn (SurveyPage $page): array => [
                'key' => (string) $page->page_key,
                'label' => (string) ($page->title !== null && $page->title !== '' ? $page->title : $page->page_key),
                'count' => $pageViews->where('page_key', $page->page_key)->count(),
            ])
            ->all();

        return [
            'steps' => [
                ['key' => '__started__', 'label' => '開始填寫', 'count' => $startedCount],
                ...$pageSteps,
                ['key' => '__submitted__', 'label' => '送出', 'count' => $submittedCount],
            ],
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
            ->toBase()
            ->map(fn (SurveyResponseEvent $event): string => $event->occurred_at->toDateString());
        $startedByDate = $events
            ->where('event', 'started')
            ->groupBy(fn (SurveyResponseEvent $event): string => $event->occurred_at->toDateString())
            ->map(fn (Collection $dailyEvents): int => $dailyEvents->count());
        $submittedByDate = $responses
            ->map(fn (SurveyResponse $response): ?string => $this->responseDateString($response))
            ->filter(fn (?string $date): bool => $date !== null)
            ->countBy();

        $dates = $eventDates
            ->merge($submittedByDate->keys())
            ->unique()
            ->sort()
            ->values();

        return array_values($dates
            ->map(function (int|string $date) use ($startedByDate, $submittedByDate): array {
                $date = (string) $date;

                return [
                    'date' => $date,
                    'started' => (int) $startedByDate->get($date, 0),
                    'submitted' => (int) $submittedByDate->get($date, 0),
                ];
            })
            ->all());
    }

    /**
     * @param  list<array{date: string, started: int, submitted: int}>  $daily
     * @return array{granularity: 'day'|'week'|'month', label: string, rows: list<array{label: string, started: int, submitted: int}>}
     */
    private function summarizeResponseTrend(array $daily): array
    {
        $granularity = $this->trendGranularity(collect($daily)->pluck('date'));
        /** @var array<string, array{label: string, started: int, submitted: int}> $rows */
        $rows = [];

        foreach ($daily as $day) {
            $period = $this->trendPeriod($day['date'], $granularity);

            if (! isset($rows[$period['key']])) {
                $rows[$period['key']] = [
                    'label' => $period['label'],
                    'started' => 0,
                    'submitted' => 0,
                ];
            }

            $rows[$period['key']]['started'] += $day['started'];
            $rows[$period['key']]['submitted'] += $day['submitted'];
        }

        return [
            'granularity' => $granularity,
            'label' => $this->trendGranularityLabel($granularity),
            'rows' => array_values($rows),
        ];
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
        $answersByFieldKey = $responses
            ->flatMap->answers
            ->groupBy(fn (SurveyAnswer $answer): string => $answer->fieldKey());

        return array_values($fields
            ->reject(fn (SurveyField $field): bool => $field->is_hidden || $field->type->isContentBlock())
            ->map(function (SurveyField $field) use ($answersByFieldKey, $fields): array {
                $answers = $answersByFieldKey->get($field->field_key);

                return $this->fieldStats(
                    $field,
                    $answers instanceof Collection ? $answers : collect(),
                    $fields,
                );
            })
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @param  Collection<int, SurveyField>  $fields
     * @return array<string, mixed>
     */
    private function fieldStats(SurveyField $field, Collection $answers, Collection $fields): array
    {
        $base = [
            'field_id' => $field->id,
            'field_key' => $field->field_key,
            'label' => $field->label,
            'type' => $field->type->value,
            'type_label' => $field->type->label(),
            'answered' => $answers->count(),
        ];

        return match ($field->type) {
            SurveyFieldType::SingleChoice,
            SurveyFieldType::Select,
            SurveyFieldType::MultipleChoice => array_merge($base, [
                'distribution' => $this->optionDistribution($field, $answers),
            ]),
            SurveyFieldType::Rating,
            SurveyFieldType::LinearScale => array_merge($base, [
                'average' => $this->average($answers),
                'distribution' => $this->numericDistribution($answers),
            ]),
            SurveyFieldType::Nps => array_merge($base, [
                'answered' => $this->validNpsAnswers($answers)->count(),
                'average' => $this->npsAverage($answers),
                'distribution' => $this->npsDistribution($answers),
                'nps' => $this->npsStats($answers),
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
            SurveyFieldType::LongText,
            SurveyFieldType::Date,
            SurveyFieldType::Time => array_merge($base, [
                'text_responses' => $this->textResponsePreviews($answers),
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

        return array_values($this->optionsForAnswers($field, $answers)
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
        $options = $this->optionsForAnswers($field, $answers);

        if ($options->isEmpty() && $source instanceof SurveyField) {
            $options = collect($source->normalizedOptions());
        }

        $counts = [];

        foreach ($answers as $answer) {
            foreach ($this->answerValues($answer) as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return array_values($options
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
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $value): array => ['value' => $value, 'count' => $count])
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, count: int}>
     */
    private function npsDistribution(Collection $answers): array
    {
        $counts = $this->validNpsAnswers($answers)
            ->pluck('score')
            ->countBy();

        return array_map(
            fn (int $score): array => [
                'value' => (string) $score,
                'count' => $counts->get($score, 0),
            ],
            range(0, 10),
        );
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return array{
     *     score: float|null,
     *     respondents: int,
     *     margin_of_error: float|null,
     *     is_low_sample: bool,
     *     low_sample_threshold: int,
     *     promoters: array{count: int, percentage: float},
     *     passives: array{count: int, percentage: float},
     *     detractors: array{count: int, percentage: float},
     *     trend: array{granularity: 'day'|'week'|'month', label: string, rows: list<array{label: string, score: float, respondents: int, margin_of_error: float|null, is_low_sample: bool}>}
     * }
     */
    private function npsStats(Collection $answers): array
    {
        $validAnswers = $this->validNpsAnswers($answers);
        $respondents = $validAnswers->count();

        if ($respondents === 0) {
            return [
                'score' => null,
                'respondents' => 0,
                'margin_of_error' => null,
                'is_low_sample' => true,
                'low_sample_threshold' => self::LOW_SAMPLE_THRESHOLD,
                'promoters' => ['count' => 0, 'percentage' => 0.0],
                'passives' => ['count' => 0, 'percentage' => 0.0],
                'detractors' => ['count' => 0, 'percentage' => 0.0],
                'trend' => $this->emptyNpsTrend(),
            ];
        }

        $promoters = $validAnswers->where('score', '>=', 9)->count();
        $passives = $validAnswers->whereBetween('score', [7, 8])->count();
        $detractors = $validAnswers->where('score', '<=', 6)->count();

        return [
            'score' => $this->npsScore($promoters, $detractors, $respondents),
            'respondents' => $respondents,
            'margin_of_error' => $this->npsMarginOfError($promoters, $detractors, $respondents),
            'is_low_sample' => $respondents < self::LOW_SAMPLE_THRESHOLD,
            'low_sample_threshold' => self::LOW_SAMPLE_THRESHOLD,
            'promoters' => $this->npsGroup($promoters, $respondents),
            'passives' => $this->npsGroup($passives, $respondents),
            'detractors' => $this->npsGroup($detractors, $respondents),
            'trend' => $this->summarizeNpsTrend($validAnswers),
        ];
    }

    /**
     * @param  Collection<int, array{score: int, date: string}>  $answers
     * @return array{granularity: 'day'|'week'|'month', label: string, rows: list<array{label: string, score: float, respondents: int, margin_of_error: float|null, is_low_sample: bool}>}
     */
    private function summarizeNpsTrend(Collection $answers): array
    {
        $granularity = $this->trendGranularity($answers->pluck('date'));
        /** @var array<string, array{label: string, respondents: int, promoters: int, detractors: int}> $periods */
        $periods = [];

        foreach ($answers as $answer) {
            $period = $this->trendPeriod($answer['date'], $granularity);

            if (! isset($periods[$period['key']])) {
                $periods[$period['key']] = [
                    'label' => $period['label'],
                    'respondents' => 0,
                    'promoters' => 0,
                    'detractors' => 0,
                ];
            }

            $periods[$period['key']]['respondents']++;

            if ($answer['score'] >= 9) {
                $periods[$period['key']]['promoters']++;
            }

            if ($answer['score'] <= 6) {
                $periods[$period['key']]['detractors']++;
            }
        }

        ksort($periods);

        $rows = array_values(array_map(
            fn (array $period): array => [
                'label' => $period['label'],
                'score' => $this->npsScore($period['promoters'], $period['detractors'], $period['respondents']),
                'respondents' => $period['respondents'],
                'margin_of_error' => $this->npsMarginOfError($period['promoters'], $period['detractors'], $period['respondents']),
                'is_low_sample' => $period['respondents'] < self::LOW_SAMPLE_THRESHOLD,
            ],
            $periods,
        ));

        return [
            'granularity' => $granularity,
            'label' => $this->trendGranularityLabel($granularity),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{granularity: 'day', label: string, rows: list<array{label: string, score: float, respondents: int, margin_of_error: float|null, is_low_sample: bool}>}
     */
    private function emptyNpsTrend(): array
    {
        return [
            'granularity' => 'day',
            'label' => $this->trendGranularityLabel('day'),
            'rows' => [],
        ];
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return Collection<int, array{score: int, date: string}>
     */
    private function validNpsAnswers(Collection $answers): Collection
    {
        return $answers
            ->map(function (SurveyAnswer $answer): ?array {
                if (! is_numeric($answer->answer_text)) {
                    return null;
                }

                $numericScore = (float) $answer->answer_text;
                $score = (int) $numericScore;
                $date = $answer->response->submitted_at?->toDateString()
                    ?? $answer->response->created_at?->toDateString();

                if ($numericScore !== (float) $score || $score < 0 || $score > 10 || $date === null) {
                    return null;
                }

                return ['score' => $score, 'date' => $date];
            })
            ->filter(fn (?array $answer): bool => $answer !== null)
            ->values();
    }

    /**
     * @return array{count: int, percentage: float}
     */
    private function npsGroup(int $count, int $respondents): array
    {
        return [
            'count' => $count,
            'percentage' => round(($count / $respondents) * 100, 1),
        ];
    }

    private function npsScore(int $promoters, int $detractors, int $respondents): float
    {
        return round((($promoters - $detractors) / $respondents) * 100, 1);
    }

    /**
     * Half-width of the 95% confidence interval around the NPS score, in NPS
     * points. NPS is a difference of two proportions, so its variance is
     * `p_promoters + p_detractors - (p_promoters - p_detractors)^2`.
     */
    private function npsMarginOfError(int $promoters, int $detractors, int $respondents): ?float
    {
        if ($respondents === 0) {
            return null;
        }

        $promoterShare = $promoters / $respondents;
        $detractorShare = $detractors / $respondents;
        $variance = $promoterShare + $detractorShare - (($promoterShare - $detractorShare) ** 2);

        return round(1.96 * sqrt(max($variance, 0.0) / $respondents) * 100, 1);
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     */
    private function npsAverage(Collection $answers): ?float
    {
        $scores = $this->validNpsAnswers($answers)->pluck('score');

        return $scores->isEmpty() ? null : round((float) $scores->avg(), 2);
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
            ->filter(fn (?float $value): bool => $value !== null)
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
            ->filter(fn (?float $value): bool => $value !== null)
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
        $options = $this->optionsForAnswers($field, $answers);
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

        return array_values($this->optionsForAnswers($field, $answers)
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

        return array_values($this->optionsForAnswers($field, $answers)
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
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{response_number: string|null, submitted_at: string|null, text: string}>
     */
    private function textResponsePreviews(Collection $answers): array
    {
        return array_values($answers
            ->filter(fn (SurveyAnswer $answer): bool => filled($answer->answer_text))
            ->sort(function (SurveyAnswer $left, SurveyAnswer $right): int {
                return [
                    $this->responseTimestamp($right->response),
                    $right->id,
                ] <=> [
                    $this->responseTimestamp($left->response),
                    $left->id,
                ];
            })
            ->take(5)
            ->map(fn (SurveyAnswer $answer): array => [
                'response_number' => filled($answer->response->response_number)
                    ? (string) $answer->response->response_number
                    : null,
                'submitted_at' => $this->responseDateTimeString($answer->response),
                'text' => (string) $answer->answer_text,
            ])
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

    private function responseDateTimeString(SurveyResponse $response): ?string
    {
        return $response->submitted_at?->format('Y/m/d H:i')
            ?? $response->created_at?->format('Y/m/d H:i');
    }

    private function responseTimestamp(SurveyResponse $response): int
    {
        return $response->submitted_at?->getTimestamp()
            ?? $response->created_at?->getTimestamp()
            ?? 0;
    }

    /**
     * @param  Collection<int, string>  $dates
     * @return 'day'|'week'|'month'
     */
    private function trendGranularity(Collection $dates): string
    {
        $dates = $dates
            ->filter(fn (string $date): bool => $date !== '')
            ->sort()
            ->values();

        if ($dates->count() < 2) {
            return 'day';
        }

        $firstDate = Carbon::parse((string) $dates->first());
        $lastDate = Carbon::parse((string) $dates->last());
        $spanInDays = $firstDate->diffInDays($lastDate) + 1;

        return match (true) {
            $spanInDays <= 31 => 'day',
            $spanInDays <= 180 => 'week',
            default => 'month',
        };
    }

    /**
     * @param  'day'|'week'|'month'  $granularity
     * @return array{key: string, label: string}
     */
    private function trendPeriod(string $date, string $granularity): array
    {
        $periodDate = Carbon::parse($date);

        if ($granularity === 'week') {
            $weekStart = $periodDate->copy()->startOfWeek(Carbon::MONDAY);
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

            return [
                'key' => $weekStart->toDateString(),
                'label' => $weekStart->format('Y/m/d').'–'.$weekEnd->format('m/d'),
            ];
        }

        if ($granularity === 'month') {
            return [
                'key' => $periodDate->format('Y-m'),
                'label' => $periodDate->format('Y 年 n 月'),
            ];
        }

        return [
            'key' => $periodDate->toDateString(),
            'label' => $periodDate->format('Y/m/d'),
        ];
    }

    /** @param 'day'|'week'|'month' $granularity */
    private function trendGranularityLabel(string $granularity): string
    {
        return match ($granularity) {
            'week' => '每週',
            'month' => '每月',
            default => '每日',
        };
    }

    /**
     * @param  Collection<int, SurveyField>  $currentFields
     * @param  Collection<int, SurveyResponse>  $responses
     * @return Collection<int, SurveyField>
     */
    private function analyticsFields(Collection $currentFields, Collection $responses): Collection
    {
        $historicalFields = $responses
            ->flatMap->answers
            ->sort(fn (SurveyAnswer $left, SurveyAnswer $right): int => $this->compareAnswersNewestFirst($left, $right))
            ->unique(fn (SurveyAnswer $answer): string => $answer->fieldKey())
            ->reject(fn (SurveyAnswer $answer): bool => $currentFields->contains(
                fn (SurveyField $field): bool => $field->field_key === $answer->fieldKey(),
            ))
            ->map(function (SurveyAnswer $answer): SurveyField {
                $field = new SurveyField();
                $field->forceFill([
                    'id' => $answer->survey_field_id,
                    'field_key' => $answer->fieldKey(),
                    'label' => $answer->fieldLabel(),
                    'type' => $answer->fieldType(),
                    'is_hidden' => false,
                    'options_json' => $answer->normalizedSnapshotOptions(),
                    'settings_json' => $answer->normalizedSnapshotSettings(),
                ]);

                return $field;
            });

        return $currentFields->concat($historicalFields)->values();
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return Collection<int, array{id: string|null, label: string, value: string, capacity: int|null, is_hidden: bool, group: string|null}>
     */
    private function optionsForAnswers(SurveyField $field, Collection $answers): Collection
    {
        $snapshotOptions = $answers
            ->sort(fn (SurveyAnswer $left, SurveyAnswer $right): int => $this->compareAnswersNewestFirst($left, $right))
            ->flatMap(fn (SurveyAnswer $answer): array => $answer->normalizedSnapshotOptions());

        $currentOptions = collect($field->normalizedOptions());

        return ($field->exists ? $currentOptions->concat($snapshotOptions) : $snapshotOptions->concat($currentOptions))
            ->unique('value')
            ->values();
    }

    private function compareAnswersNewestFirst(SurveyAnswer $left, SurveyAnswer $right): int
    {
        $leftVersion = (int) ($left->response->schema_version_id ?? 0);
        $rightVersion = (int) ($right->response->schema_version_id ?? 0);

        return [$rightVersion, $right->id] <=> [$leftVersion, $left->id];
    }
}
