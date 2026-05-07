<?php

namespace Lalalili\SurveyCore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

class SurveyTokenResolved
{
    use Dispatchable;

    public function __construct(
        public readonly SurveyToken $token,
        public readonly SurveyRecipient $recipient,
    ) {}
}
