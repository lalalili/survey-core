<?php

namespace Lalalili\SurveyCore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;

class SurveySubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly SurveyResponse $response,
        public readonly Survey $survey,
        public readonly ?SurveyRecipient $recipient = null,
    ) {
    }
}
