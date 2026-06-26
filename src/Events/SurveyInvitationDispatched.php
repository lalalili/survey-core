<?php

namespace Lalalili\SurveyCore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

/**
 * Fired after a survey invitation token has been issued and the email
 * is ready to be sent.  Listeners are expected to handle the actual
 * delivery (e.g. email-campaign's HandleSurveyInvitationDispatched).
 */
class SurveyInvitationDispatched
{
    use Dispatchable;

    public function __construct(
        public readonly SurveyRecipient $recipient,
        public readonly SurveyToken $token,
        public readonly string $surveyUrl,
    ) {}
}
