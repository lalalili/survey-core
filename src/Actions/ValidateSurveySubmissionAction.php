<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Lalalili\SurveyCore\Data\ResolvedToken;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

class ValidateSurveySubmissionAction
{
    /**
     * @param  array<string, mixed>  $visibleAnswers
     */
    public function execute(Survey $survey, array $visibleAnswers, ?ResolvedToken $tokenContext = null): void
    {
        if (! $survey->isAcceptingSubmissions()) {
            throw new SurveyNotAvailableException("Survey '{$survey->title}' is not currently accepting submissions.");
        }

        // Only validate fields that are:
        // 1. Not statically hidden (is_hidden = false)
        // 2. Conditionally visible given the submitted answers (branching)
        $activeFields = $survey->fields->filter(function (SurveyField $f) use ($visibleAnswers) {
            if ($f->is_hidden) {
                return false;
            }

            return $f->isConditionallyVisible($visibleAnswers);
        });

        $rules = $this->buildRules($activeFields);
        $validator = Validator::make($visibleAnswers, $rules);

        if ($validator->fails()) {
            throw new SurveyValidationException($validator->errors()->toArray());
        }

        $this->validateChoiceOptions($activeFields, $visibleAnswers);
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @return array<string, array<int, mixed>>
     */
    private function buildRules(Collection $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $fieldRules = [];

            if ($field->is_required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            $fieldRules = array_merge($fieldRules, $this->typeRules($field));

            if (! empty($field->validation_rules)) {
                $fieldRules = array_merge($fieldRules, $field->validation_rules);
            }

            $rules[$field->field_key] = $fieldRules;
        }

        return $rules;
    }

    /** @return array<int, string> */
    private function typeRules(SurveyField $field): array
    {
        return match ($field->type) {
            SurveyFieldType::Email          => ['email'],
            SurveyFieldType::Date           => ['date'],
            SurveyFieldType::Rating         => ['integer', 'min:1', 'max:5'],
            SurveyFieldType::MultipleChoice => ['array'],
            SurveyFieldType::ShortText, SurveyFieldType::LongText,
            SurveyFieldType::Phone, SurveyFieldType::SingleChoice,
            SurveyFieldType::Select => ['string'],
            default                 => [],
        };
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @param  array<string, mixed>           $answers
     */
    private function validateChoiceOptions(Collection $fields, array $answers): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! $field->type->requiresOptions()) {
                continue;
            }

            $value = $answers[$field->field_key] ?? null;

            if ($value === null) {
                continue;
            }

            $validOptions = $field->optionValues();

            if (empty($validOptions)) {
                continue;
            }

            if ($field->type === SurveyFieldType::MultipleChoice) {
                // Cast both sides to string so numeric keys don't cause false negatives
                $submitted = array_map('strval', (array) $value);
                $invalid = array_diff($submitted, $validOptions);
                if (! empty($invalid)) {
                    $errors[$field->field_key][] = 'Invalid option(s): ' . implode(', ', $invalid);
                }
            } elseif (! in_array((string) $value, $validOptions, true)) {
                $errors[$field->field_key][] = "Invalid option: {$value}";
            }
        }

        if (! empty($errors)) {
            throw new SurveyValidationException($errors);
        }
    }
}
