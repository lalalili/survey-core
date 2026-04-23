<?php

namespace Lalalili\SurveyCore\Contracts;

use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;

interface PersonalizationResolver
{
    /**
     * Resolve a personalized field value from the recipient's payload.
     *
     * Returns null when the personalized_key is absent from the payload.
     */
    public function resolve(SurveyRecipient $recipient, SurveyField $field): mixed;
}
