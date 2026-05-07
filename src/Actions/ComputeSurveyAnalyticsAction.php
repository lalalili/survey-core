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
    public function execute(Survey $survey): array
    {
        $survey->loadMissing(['fields', 'collectors']);

        $submittedResponses = SurveyResponse::query()
            ->with('answers')
            ->where('survey_id', $survey->id)
            ->whereNotNull('submitted_at')
            ->get();

        $events = SurveyResponseEvent::query()
            ->where('survey_id', $survey->id)
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
        $dates = $events
            ->map(fn (SurveyResponseEvent $event): string => $event->occurred_at->toDateString())
            ->merge($responses->map(fn (SurveyResponse $response): string => $response->submitted_at?->toDateString() ?? $response->created_at->toDateString()))
            ->unique()
            ->sort()
            ->values();

        return $dates
            ->map(fn (string $date): array => [
                'date' => $date,
                'started' => $events->filter(fn (SurveyResponseEvent $event): bool => $event->event === 'started' && $event->occurred_at->toDateString() === $date)->count(),
                'submitted' => $responses->filter(fn (SurveyResponse $response): bool => ($response->submitted_at?->toDateString() ?? $response->created_at->toDateString()) === $date)->count(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, SurveyCollector>  $collectors
     * @param  Collection<int, SurveyResponseEvent>  $events
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array{collector_id: int, name: string, type: string, slug: string, started: int, submitted: int, completion_rate: float}>
     */
    private function collectorPerformance(Collection $collectors, Collection $events, Collection $responses): array
    {
        return $collectors
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
            ->all();
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function questionStats(Collection $fields, Collection $responses): array
    {
        return $fields
            ->reject(fn (SurveyField $field): bool => $field->is_hidden || $field->type->isContentBlock())
            ->map(fn (SurveyField $field): array => $this->fieldStats($field, $responses))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SurveyResponse>  $responses
     * @return array<string, mixed>
     */
    private function fieldStats(SurveyField $field, Collection $responses): array
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
            SurveyFieldType::SingleChoice, SurveyFieldType::Select, SurveyFieldType::MultipleChoice => array_merge($base, [
                'distribution' => $this->optionDistribution($field, $answers),
            ]),
            SurveyFieldType::Rating, SurveyFieldType::Nps => array_merge($base, [
                'average' => $this->average($answers),
                'distribution' => $this->numericDistribution($answers),
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

        return collect($field->normalizedOptions())
            ->map(fn (array $option): array => [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
                'count' => $counts[(string) $option['value']] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SurveyAnswer>  $answers
     * @return list<array{value: string, count: int}>
     */
    private function numericDistribution(Collection $answers): array
    {
        return $answers
            ->map(fn (SurveyAnswer $answer): ?string => $answer->answer_text !== null ? (string) $answer->answer_text : null)
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $value): array => ['value' => $value, 'count' => $count])
            ->values()
            ->all();
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

    private function rate(int $count, int $total): float
    {
        if ($total < 1) {
            return 0.0;
        }

        return round(($count / $total) * 100, 2);
    }
}
