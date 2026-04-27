<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\Mail;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Mail\SurveyInvitationMail;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

class SendSurveyInvitationAction
{
    public function __construct(private readonly GenerateSurveyTokenAction $generateToken)
    {
    }

    /**
     * Send (or resend) an invitation to a single recipient.
     *
     * On resend: deactivates existing active tokens and issues a fresh one.
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

        $surveyUrl = route('survey.show', $recipient->survey->public_key) . '?t=' . $token->token;

        Mail::to($recipient->email)
            ->queue(new SurveyInvitationMail($recipient, $surveyUrl));

        return $token;
    }
}
