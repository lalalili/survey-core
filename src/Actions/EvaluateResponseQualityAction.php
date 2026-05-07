<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyResponse;

class EvaluateResponseQualityAction
{
    /**
     * @param  array{elapsed_ms?: int|null, honeypot_hit?: bool, ip?: string|null}  $context
     */
    public function execute(SurveyResponse $response, array $context = []): SurveyResponseQualityStatus
    {
        $flags = [];
        $elapsedMs = $context['elapsed_ms'] ?? null;

        if ($elapsedMs !== null && $elapsedMs < (int) config('survey-core.security.min_submission_ms', 3000)) {
            $flags[] = 'too_fast';
        }

        if ((bool) ($context['honeypot_hit'] ?? false)) {
            $flags[] = 'honeypot_hit';
        }

        if ($this->allSameAnswer($response)) {
            $flags[] = 'all_same_answer';
        }

        $ip = (string) ($context['ip'] ?? '');
        if ($ip !== '' && in_array($ip, config('survey-core.security.ip_blacklist', []), true)) {
            $flags[] = 'ip_blacklisted';
        }

        $status = match (true) {
            in_array('honeypot_hit', $flags, true) => SurveyResponseQualityStatus::Quarantined,
            count($flags) > 0 => SurveyResponseQualityStatus::Flagged,
            default => SurveyResponseQualityStatus::Accepted,
        };

        $response->update([
            'quality_status' => $status,
            'quality_flags_json' => $flags === [] ? null : $flags,
        ]);

        return $status;
    }

    private function allSameAnswer(SurveyResponse $response): bool
    {
        $response->loadMissing('answers.field');

        $choiceAnswers = $response->answers
            ->filter(fn (SurveyAnswer $answer): bool => in_array($answer->field->type, [
                SurveyFieldType::SingleChoice,
                SurveyFieldType::Select,
                SurveyFieldType::Rating,
                SurveyFieldType::Nps,
            ], true))
            ->map(fn (SurveyAnswer $answer): string => (string) $answer->getValue())
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        return $choiceAnswers->count() > 1 && $choiceAnswers->unique()->count() === 1;
    }
}
