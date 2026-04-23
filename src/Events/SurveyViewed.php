<?php

namespace Lalalili\SurveyCore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;

class SurveyViewed
{
    use Dispatchable;

    public function __construct(
        public readonly Survey $survey,
        public readonly ?SurveyRecipient $recipient = null,
    ) {
    }
}
