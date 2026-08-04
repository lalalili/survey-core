<?php

namespace Lalalili\SurveyCore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Lalalili\SurveyCore\Models\Survey;

class SurveyClosed
{
    use Dispatchable;

    public function __construct(
        public readonly Survey $survey,
    ) {
    }
}
