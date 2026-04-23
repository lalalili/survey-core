<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Data\ResolvedToken;
use Lalalili\SurveyCore\Exceptions\InvalidSurveyTokenException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyToken;

class ResolveSurveyTokenAction
{
    public function execute(Survey $survey, string $rawToken): ResolvedToken
    {
        $token = SurveyToken::with('recipient')
            ->where('token', $rawToken)
            ->first();

        if (! $token) {
            throw new InvalidSurveyTokenException('Token not found.');
        }

        if ($token->survey_id !== $survey->id) {
            throw new InvalidSurveyTokenException('Token does not belong to this survey.');
        }

        if (! $token->isActive()) {
            throw new InvalidSurveyTokenException('Token is inactive.');
        }

        if ($token->isExpired()) {
            throw new InvalidSurveyTokenException('Token has expired.');
        }

        if ($token->isExhausted()) {
            throw new InvalidSurveyTokenException('Token has reached its submission limit.');
        }

        $recipient = $token->recipient;
        $payload = $recipient->payload_json ?? [];

        return new ResolvedToken($token, $recipient, $payload);
    }
}
