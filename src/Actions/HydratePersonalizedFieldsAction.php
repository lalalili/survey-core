<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Collection;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Data\HiddenAnswerMap;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;

class HydratePersonalizedFieldsAction
{
    public function __construct(
        private readonly PersonalizationResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, SurveyField>  $fields
     */
    public function execute(Collection $fields, SurveyRecipient $recipient): HiddenAnswerMap
    {
        $values = [];

        foreach ($fields as $field) {
            if (! $field->is_personalized) {
                continue;
            }

            $values[$field->field_key] = $this->resolver->resolve($recipient, $field);
        }

        return new HiddenAnswerMap($values);
    }
}
