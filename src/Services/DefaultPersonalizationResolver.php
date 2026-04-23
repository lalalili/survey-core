<?php

namespace Lalalili\SurveyCore\Services;

use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;

class DefaultPersonalizationResolver implements PersonalizationResolver
{
    /**
     * Resolve a personalized field value by looking up the field's
     * personalized_key inside the recipient's payload_json.
     * Returns null when the key is absent.
     */
    public function resolve(SurveyRecipient $recipient, SurveyField $field): mixed
    {
        if (empty($field->personalized_key)) {
            return null;
        }

        $payload = $recipient->payload_json ?? [];

        return $payload[$field->personalized_key] ?? null;
    }
}
