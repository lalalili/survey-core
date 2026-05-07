<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Lalalili\SurveyCore\Data\ResolvedToken;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Support\JumpLogicResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

        // Compute which pages are reachable given jump logic in the published schema.
        $visitedPages = JumpLogicResolver::resolveVisitedPages($survey, $visibleAnswers);

        // Only validate fields that are:
        // 1. Not statically hidden (is_hidden = false)
        // 2. Conditionally visible given the submitted answers (branching)
        // 3. On a page that was reached (jump logic)
        $activeFields = $survey->fields->filter(function (SurveyField $f) use ($visibleAnswers, $visitedPages) {
            if ($f->is_hidden) {
                return false;
            }

            if ($f->type->isContentBlock()) {
                return false;
            }

            if ($visitedPages !== null && ! in_array($f->survey_page_id, $visitedPages, true)) {
                return false;
            }

            return $f->isConditionallyVisible($visibleAnswers);
        });

        $rules = $this->buildRules($activeFields);
        $validator = Validator::make($visibleAnswers, $rules);
        $validator->after(fn (ValidationValidator $validator) => $this->validateComplexFields($validator, $activeFields, $visibleAnswers));

        if ($validator->fails()) {
            throw new SurveyValidationException($validator->errors()->toArray());
        }

        $this->validateChoiceOptions($activeFields, $visibleAnswers);
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @return array<string, list<mixed>>
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
                $fieldRules = array_merge($fieldRules, array_values($field->validation_rules));
            }

            $rules[$field->field_key] = $fieldRules;
        }

        return $rules;
    }

    /** @return array<int, string> */
    private function typeRules(SurveyField $field): array
    {
        return match ($field->type) {
            SurveyFieldType::Email => ['email'],
            SurveyFieldType::Date => ['date'],
            SurveyFieldType::Number => array_values(array_filter([
                'numeric',
                isset($field->settings_json['min']) ? 'min:'.$field->settings_json['min'] : null,
                isset($field->settings_json['max']) ? 'max:'.$field->settings_json['max'] : null,
            ])),
            SurveyFieldType::Nps => ['integer', 'min:0', 'max:10'],
            SurveyFieldType::Rating => ['integer', 'min:1', 'max:5'],
            SurveyFieldType::MultipleChoice, SurveyFieldType::MatrixSingle,
            SurveyFieldType::MatrixMulti, SurveyFieldType::Ranking,
            SurveyFieldType::CascadeSelect,
            SurveyFieldType::FileUpload, SurveyFieldType::Signature,
            SurveyFieldType::Address => ['array'],
            SurveyFieldType::ShortText, SurveyFieldType::LongText,
            SurveyFieldType::Phone, SurveyFieldType::SingleChoice,
            SurveyFieldType::Select, SurveyFieldType::SectionTitle,
            SurveyFieldType::DescriptionBlock => ['string'],
            default => [],
        };
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @param  array<string, mixed>  $answers
     */
    private function validateChoiceOptions(Collection $fields, array $answers): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! $field->type->requiresOptions()) {
                continue;
            }

            if ($field->type === SurveyFieldType::Ranking) {
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
                    $errors[$field->field_key][] = 'Invalid option(s): '.implode(', ', $invalid);
                }
            } elseif (! in_array((string) $value, $validOptions, true)) {
                $errors[$field->field_key][] = "Invalid option: {$value}";
            }
        }

        if (! empty($errors)) {
            throw new SurveyValidationException($errors);
        }
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @param  array<string, mixed>  $answers
     */
    private function validateComplexFields(ValidationValidator $validator, Collection $fields, array $answers): void
    {
        foreach ($fields as $field) {
            $value = $answers[$field->field_key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            match ($field->type) {
                SurveyFieldType::Phone => $this->validatePhone($validator, $field, $value),
                SurveyFieldType::Number => $this->validateNumberRules($validator, $field, $value),
                SurveyFieldType::MultipleChoice => $this->validateSelectionCount($validator, $field, (array) $value),
                SurveyFieldType::MatrixSingle, SurveyFieldType::MatrixMulti => $this->validateMatrix($validator, $field, (array) $value),
                SurveyFieldType::CascadeSelect => $this->validateCascadeSelect($validator, $field, (array) $value),
                SurveyFieldType::Ranking => $this->validateRanking($validator, $field, (array) $value),
                SurveyFieldType::FileUpload => $this->validateFileUploadAnswer($validator, $field, (array) $value),
                SurveyFieldType::Signature => $this->validateSignature($validator, $field, (array) $value),
                SurveyFieldType::Address => $this->validateAddress($validator, $field, (array) $value),
                SurveyFieldType::ShortText, SurveyFieldType::LongText => $this->validateTextRules($validator, $field, (string) $value),
                default => null,
            };
        }
    }

    private function validateNumberRules(ValidationValidator $validator, SurveyField $field, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $rules = $field->validation_rules ?? [];
        $number = (float) $value;

        if (isset($rules['min_value']) && $number < (float) $rules['min_value']) {
            $validator->errors()->add($field->field_key, 'Number is too small.');
        }

        if (isset($rules['max_value']) && $number > (float) $rules['max_value']) {
            $validator->errors()->add($field->field_key, 'Number is too large.');
        }
    }

    private function validatePhone(ValidationValidator $validator, SurveyField $field, mixed $value): void
    {
        if (! preg_match('/^[0-9+\-\s()]+$/', (string) $value)) {
            $validator->errors()->add($field->field_key, 'Phone number may only contain digits and phone symbols.');
        }

        $this->validateTextRules($validator, $field, (string) $value);
    }

    /** @param array<int, mixed> $value */
    private function validateSelectionCount(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $rules = $field->validation_rules ?? [];
        $count = count($value);

        if (isset($rules['min_selections']) && $count < (int) $rules['min_selections']) {
            $validator->errors()->add($field->field_key, 'Not enough selections.');
        }

        if (isset($rules['max_selections']) && $count > (int) $rules['max_selections']) {
            $validator->errors()->add($field->field_key, 'Too many selections.');
        }
    }

    /** @param array<string, mixed> $value */
    private function validateMatrix(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $matrixRows = is_array($field->settings_json['matrix_rows'] ?? null) ? $field->settings_json['matrix_rows'] : [];
        $matrixCols = is_array($field->settings_json['matrix_cols'] ?? null) ? $field->settings_json['matrix_cols'] : [];
        $rows = collect($matrixRows);
        $validCols = collect($matrixCols)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        foreach ($rows as $row) {
            $rowId = (string) ($row['id'] ?? '');
            $answer = $value[$rowId] ?? null;

            if ($field->is_required && ($answer === null || $answer === '' || $answer === [])) {
                $validator->errors()->add($field->field_key, "Matrix row {$rowId} is required.");

                continue;
            }

            if ($answer === null || $answer === '') {
                continue;
            }

            $submitted = array_map('strval', (array) $answer);
            if ($field->type === SurveyFieldType::MatrixSingle && count($submitted) !== 1) {
                $validator->errors()->add($field->field_key, "Matrix row {$rowId} must contain one value.");
            }

            if (array_diff($submitted, $validCols) !== []) {
                $validator->errors()->add($field->field_key, "Matrix row {$rowId} contains an invalid column.");
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function validateCascadeSelect(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $cascadeLevels = is_array($field->settings_json['cascade_levels'] ?? null) ? $field->settings_json['cascade_levels'] : [];
        $levels = collect($cascadeLevels)
            ->filter(fn (mixed $level): bool => is_array($level))
            ->values();

        if ($levels->isEmpty()) {
            return;
        }

        foreach ($levels as $level) {
            $levelId = (string) ($level['id'] ?? '');
            if ($levelId === '') {
                continue;
            }

            if ($field->is_required && blank($value[$levelId] ?? null)) {
                $validator->errors()->add($field->field_key, "Cascade level {$levelId} is required.");
            }
        }
    }

    /** @param array<int, mixed> $value */
    private function validateRanking(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $optionIds = collect($field->options_json ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $submitted = array_map('strval', $value);

        if ($field->is_required && count($submitted) !== count($optionIds)) {
            $validator->errors()->add($field->field_key, 'Ranking must include every option.');
        }

        if (array_diff($submitted, $optionIds) !== [] || count($submitted) !== count(array_unique($submitted))) {
            $validator->errors()->add($field->field_key, 'Ranking contains invalid options.');
        }
    }

    /** @param array<string, mixed> $value */
    private function validateFileUploadAnswer(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        if ($field->is_required && empty($value['media_id'])) {
            $validator->errors()->add($field->field_key, 'A file upload is required.');
        }

        if (! empty($value['media_id']) && ! Media::query()->whereKey((int) $value['media_id'])->where('collection_name', 'survey_files')->exists()) {
            $validator->errors()->add($field->field_key, 'Uploaded file was not found.');
        }

        $maxSizeMb = (int) ($field->settings_json['max_size_mb'] ?? 0);
        if ($maxSizeMb > 0 && (int) ($value['size'] ?? 0) > $maxSizeMb * 1024 * 1024) {
            $validator->errors()->add($field->field_key, 'Uploaded file is too large.');
        }
    }

    /** @param array<string, mixed> $value */
    private function validateSignature(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $dataUrl = (string) ($value['data_url'] ?? '');
        if ($field->is_required && strlen($dataUrl) < 200) {
            $validator->errors()->add($field->field_key, 'Signature is required.');
        }
    }

    /** @param array<string, mixed> $value */
    private function validateAddress(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $enabled = $field->settings_json['fields_enabled'] ?? ['country', 'city', 'district', 'address', 'postal_code'];
        $lockedCountry = $field->settings_json['country_locked'] ?? null;

        foreach ($enabled as $key) {
            if ($field->is_required && blank($value[$key] ?? null)) {
                $validator->errors()->add($field->field_key.'.'.$key, 'Address field is required.');
            }
        }

        if ($lockedCountry !== null && ($value['country'] ?? $lockedCountry) !== $lockedCountry) {
            $validator->errors()->add($field->field_key.'.country', 'Country cannot be changed.');
        }
    }

    private function validateTextRules(ValidationValidator $validator, SurveyField $field, string $value): void
    {
        $rules = $field->validation_rules ?? [];

        if (isset($rules['min_length']) && mb_strlen($value) < (int) $rules['min_length']) {
            $validator->errors()->add($field->field_key, (string) ($rules['pattern_label'] ?? 'Text is too short.'));
        }

        if (isset($rules['max_length']) && mb_strlen($value) > (int) $rules['max_length']) {
            $validator->errors()->add($field->field_key, 'Text is too long.');
        }

        if (! empty($rules['regex']) && @preg_match('/'.$rules['regex'].'/u', '') !== false && ! preg_match('/'.$rules['regex'].'/u', $value)) {
            $validator->errors()->add($field->field_key, (string) ($rules['pattern_label'] ?? 'Text format is invalid.'));
        }
    }
}
