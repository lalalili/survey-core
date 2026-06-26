<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Events\SurveyInvitationDispatched;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

class SendSurveyInvitationAction
{
    public function __construct(private readonly GenerateSurveyTokenAction $generateToken) {}

    /**
     * Issue (or reissue) an invitation token and fire SurveyInvitationDispatched.
     *
     * Delivery is handled by registered listeners — email-campaign registers
     * HandleSurveyInvitationDispatched, which creates an EmailDelivery record
     * and queues the send job.
     *
     * On resend, existing active tokens are deactivated and a fresh token issued.
     */
    public function execute(SurveyRecipient $recipient, bool $resend = false): SurveyToken
    {
        $recipient->loadMissing('survey');

        if ($resend) {
            $recipient->tokens()
                ->where('status', SurveyTokenStatus::Active->value)
                ->update(['status' => SurveyTokenStatus::Inactive->value]);
        }

        $token = $recipient->tokens()
            ->where('status', SurveyTokenStatus::Active->value)
            ->latest()
            ->first();

        if (! $token) {
            $token = $this->generateToken->execute($recipient->survey, $recipient);
        }

        $surveyUrl = route('survey.show', $recipient->survey->public_key).'?t='.$token->token;

        SurveyInvitationDispatched::dispatch($recipient, $token, $surveyUrl);

        return $token;
    }
}
