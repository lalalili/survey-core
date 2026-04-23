<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

class GenerateSurveyTokenAction
{
    public function execute(
        Survey $survey,
        SurveyRecipient $recipient,
        ?int $maxSubmissions = null,
        ?int $lifetimeMinutes = null,
    ): SurveyToken {
        $expiresAt = null;

        if ($lifetimeMinutes !== null) {
            $expiresAt = now()->addMinutes($lifetimeMinutes);
        } elseif ($defaultMinutes = config('survey-core.token_lifetime_minutes')) {
            $expiresAt = now()->addMinutes((int) $defaultMinutes);
        }

        return SurveyToken::create([
            'survey_id'           => $survey->id,
            'survey_recipient_id' => $recipient->id,
            'max_submissions'     => $maxSubmissions ?? config('survey-core.default_max_submissions'),
            'expires_at'          => $expiresAt,
            'status'              => SurveyTokenStatus::Active,
        ]);
    }
}
