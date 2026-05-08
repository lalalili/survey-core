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
            throw new SurveyNotAvailableException('此問卷目前未開放填寫。');
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
        $validator = Validator::make(
            $visibleAnswers,
            $rules,
            $this->validationMessages(),
            $this->validationAttributes($activeFields),
        );
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
                $fieldRules = array_merge($fieldRules, $this->laravelValidationRules($field->validation_rules));
            }

            $rules[$field->field_key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return array<int, string>
     */
    private function laravelValidationRules(array $rules): array
    {
        return collect($rules)
            ->filter(fn (mixed $rule, mixed $key): bool => is_int($key) && is_string($rule) && $rule !== '')
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'required' => '「:attribute」為必填，請完成填寫。',
            'array' => '「:attribute」的填答格式不正確，請重新填寫。',
            'string' => '「:attribute」的填答格式不正確，請輸入文字。',
            'email' => '「:attribute」請輸入有效的電子信箱。',
            'regex' => '「:attribute」格式不正確，請依題目提示填寫。',
            'date' => '「:attribute」請輸入有效的日期。',
            'date_format' => '「:attribute」請輸入有效的時間。',
            'numeric' => '「:attribute」請輸入數字。',
            'integer' => '「:attribute」請輸入整數。',
            'min.numeric' => '「:attribute」不可小於 :min。',
            'max.numeric' => '「:attribute」不可大於 :max。',
            'min.integer' => '「:attribute」不可小於 :min。',
            'max.integer' => '「:attribute」不可大於 :max。',
        ];
    }

    /**
     * @param  Collection<int, SurveyField>  $fields
     * @return array<string, string>
     */
    private function validationAttributes(Collection $fields): array
    {
        return $fields
            ->mapWithKeys(fn (SurveyField $field): array => [$field->field_key => $field->label])
            ->all();
    }

    /** @return array<int, string> */
    private function typeRules(SurveyField $field): array
    {
        return match ($field->type) {
            SurveyFieldType::Email => ['email'],
            SurveyFieldType::Phone => ['regex:/^09\d{8}$/'],
            SurveyFieldType::ShortText => $this->shortTextFormatRules($field),
            SurveyFieldType::Date => ['date'],
            SurveyFieldType::Time => ['date_format:H:i'],
            SurveyFieldType::Number => array_values(array_filter([
                'numeric',
                isset($field->settings_json['min']) ? 'min:'.$field->settings_json['min'] : null,
                isset($field->settings_json['max']) ? 'max:'.$field->settings_json['max'] : null,
            ])),
            SurveyFieldType::LinearScale => array_values(array_filter([
                'numeric',
                isset($field->settings_json['min']) ? 'min:'.$field->settings_json['min'] : null,
                isset($field->settings_json['max']) ? 'max:'.$field->settings_json['max'] : null,
            ])),
            SurveyFieldType::Nps => ['integer', 'min:0', 'max:10'],
            SurveyFieldType::Rating => ['integer', 'min:1', 'max:5'],
            SurveyFieldType::MultipleChoice, SurveyFieldType::MatrixSingle,
            SurveyFieldType::MatrixMulti, SurveyFieldType::Ranking,
            SurveyFieldType::ConstantSum,
            SurveyFieldType::CascadeSelect,
            SurveyFieldType::FileUpload, SurveyFieldType::Signature,
            SurveyFieldType::Address => ['array'],
            SurveyFieldType::LongText, SurveyFieldType::SingleChoice,
            SurveyFieldType::Select, SurveyFieldType::SectionTitle,
            SurveyFieldType::DescriptionBlock => ['string'],
            default => [],
        };
    }

    /** @return array<int, string> */
    private function shortTextFormatRules(SurveyField $field): array
    {
        return match ($field->settings_json['input_format'] ?? null) {
            'email' => ['string', 'email'],
            'mobile_tw' => ['string', 'regex:/^09\d{8}$/'],
            default => ['string'],
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

            if (in_array($field->type, [SurveyFieldType::Ranking, SurveyFieldType::ConstantSum], true)) {
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
                    $errors[$field->field_key][] = "「{$field->label}」包含不存在的選項，請重新選擇。";
                }
            } elseif (! in_array((string) $value, $validOptions, true)) {
                $errors[$field->field_key][] = "「{$field->label}」包含不存在的選項，請重新選擇。";
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
                SurveyFieldType::ConstantSum => $this->validateConstantSum($validator, $field, (array) $value),
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
            $validator->errors()->add($field->field_key, "「{$field->label}」不可小於 {$rules['min_value']}。");
        }

        if (isset($rules['max_value']) && $number > (float) $rules['max_value']) {
            $validator->errors()->add($field->field_key, "「{$field->label}」不可大於 {$rules['max_value']}。");
        }
    }

    private function validatePhone(ValidationValidator $validator, SurveyField $field, mixed $value): void
    {
        if (! preg_match('/^09\d{8}$/', (string) $value)) {
            $validator->errors()->add($field->field_key, "「{$field->label}」請輸入 09 開頭的 10 碼手機號碼。");
        }

        $this->validateTextRules($validator, $field, (string) $value);
    }

    /** @param array<int, mixed> $value */
    private function validateSelectionCount(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $rules = $field->validation_rules ?? [];
        $count = count($value);

        if (isset($rules['min_selections']) && $count < (int) $rules['min_selections']) {
            $validator->errors()->add($field->field_key, "「{$field->label}」至少需選擇 {$rules['min_selections']} 項。");
        }

        if (isset($rules['max_selections']) && $count > (int) $rules['max_selections']) {
            $validator->errors()->add($field->field_key, "「{$field->label}」最多只能選擇 {$rules['max_selections']} 項。");
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
            $rowLabel = $this->matrixRowLabel($row);

            if ($field->is_required && ($answer === null || $answer === '' || $answer === [])) {
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$rowLabel}」尚未選擇。");

                continue;
            }

            if ($answer === null || $answer === '') {
                continue;
            }

            $submitted = array_map('strval', (array) $answer);
            if ($field->type === SurveyFieldType::MatrixSingle && count($submitted) !== 1) {
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$rowLabel}」只能選擇一個選項。");
            }

            if (array_diff($submitted, $validCols) !== []) {
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$rowLabel}」包含不存在的選項，請重新選擇。");
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
                $levelLabel = (string) ($level['label'] ?? $levelId);
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$levelLabel}」尚未選擇。");
            }
        }
    }

    /** @param array<int, mixed> $value */
    private function validateRanking(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $optionValues = $field->optionValues();
        $submitted = array_map('strval', $value);

        if ($field->is_required && count($submitted) !== count($optionValues)) {
            $validator->errors()->add($field->field_key, "「{$field->label}」需要完成所有選項的排序。");
        }

        if (array_diff($submitted, $optionValues) !== [] || count($submitted) !== count(array_unique($submitted))) {
            $validator->errors()->add($field->field_key, "「{$field->label}」排序內容不正確，請重新排序。");
        }
    }

    /** @param array<string, mixed> $value */
    private function validateConstantSum(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $optionValues = $field->optionValues();
        $submittedKeys = array_map('strval', array_keys($value));

        if (array_diff($submittedKeys, $optionValues) !== []) {
            $validator->errors()->add($field->field_key, "「{$field->label}」包含不存在的選項，請重新填寫。");
        }

        $sum = 0.0;
        foreach ($optionValues as $optionValue) {
            $answer = $value[$optionValue] ?? null;

            if ($field->is_required && ($answer === null || $answer === '')) {
                $optionLabel = $this->optionLabel($field, $optionValue);
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$optionLabel}」尚未填寫，請填入數字。");

                continue;
            }

            if ($answer === null || $answer === '') {
                continue;
            }

            if (! is_numeric($answer)) {
                $optionLabel = $this->optionLabel($field, $optionValue);
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$optionLabel}」必須是數字。");

                continue;
            }

            $sum += (float) $answer;
        }

        if (isset($field->settings_json['total'])) {
            $total = (float) $field->settings_json['total'];
            if (abs($sum - $total) > 0.00001) {
                $validator->errors()->add(
                    $field->field_key,
                    "「{$field->label}」目前合計為 {$this->formatNumber($sum)}，需等於 {$this->formatNumber($total)}，請調整各項數字。"
                );
            }
        }
    }

    private function optionLabel(SurveyField $field, string $optionValue): string
    {
        return $field->optionsForDisplay()[$optionValue] ?? $optionValue;
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 5, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /** @param array<string, mixed> $value */
    private function validateFileUploadAnswer(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        if ($field->is_required && empty($value['media_id'])) {
            $validator->errors()->add($field->field_key, "「{$field->label}」請上傳檔案。");
        }

        if (! empty($value['media_id']) && ! Media::query()->whereKey((int) $value['media_id'])->where('collection_name', 'survey_files')->exists()) {
            $validator->errors()->add($field->field_key, "「{$field->label}」上傳的檔案不存在，請重新上傳。");
        }

        $maxSizeMb = (int) ($field->settings_json['max_size_mb'] ?? 0);
        if ($maxSizeMb > 0 && (int) ($value['size'] ?? 0) > $maxSizeMb * 1024 * 1024) {
            $validator->errors()->add($field->field_key, "「{$field->label}」檔案大小不可超過 {$maxSizeMb} MB。");
        }
    }

    /** @param array<string, mixed> $value */
    private function validateSignature(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $dataUrl = (string) ($value['data_url'] ?? '');
        if ($field->is_required && strlen($dataUrl) < 200) {
            $validator->errors()->add($field->field_key, "「{$field->label}」請完成簽名。");
        }
    }

    /** @param array<string, mixed> $value */
    private function validateAddress(ValidationValidator $validator, SurveyField $field, array $value): void
    {
        $enabled = $field->settings_json['fields_enabled'] ?? ['country', 'city', 'district', 'address', 'postal_code'];
        $lockedCountry = $field->settings_json['country_locked'] ?? null;

        foreach ($enabled as $key) {
            if ($field->is_required && blank($value[$key] ?? null)) {
                $validator->errors()->add($field->field_key.'.'.$key, "「{$field->label}」的「{$this->addressFieldLabel((string) $key)}」尚未填寫。");
            }
        }

        if ($lockedCountry !== null && ($value['country'] ?? $lockedCountry) !== $lockedCountry) {
            $validator->errors()->add($field->field_key.'.country', "「{$field->label}」的國家不可變更。");
        }
    }

    private function validateTextRules(ValidationValidator $validator, SurveyField $field, string $value): void
    {
        $rules = $field->validation_rules ?? [];

        if (isset($rules['min_length']) && mb_strlen($value) < (int) $rules['min_length']) {
            $validator->errors()->add($field->field_key, (string) ($rules['pattern_label'] ?? "「{$field->label}」至少需輸入 {$rules['min_length']} 個字。"));
        }

        if (isset($rules['max_length']) && mb_strlen($value) > (int) $rules['max_length']) {
            $validator->errors()->add($field->field_key, "「{$field->label}」最多只能輸入 {$rules['max_length']} 個字。");
        }

        if (! empty($rules['regex']) && @preg_match('/'.$rules['regex'].'/u', '') !== false && ! preg_match('/'.$rules['regex'].'/u', $value)) {
            $validator->errors()->add($field->field_key, (string) ($rules['pattern_label'] ?? "「{$field->label}」格式不正確，請依題目提示填寫。"));
        }
    }

    /** @param array<string, mixed> $row */
    private function matrixRowLabel(array $row): string
    {
        return (string) ($row['label'] ?? $row['id'] ?? '此列');
    }

    private function addressFieldLabel(string $key): string
    {
        return match ($key) {
            'country' => '國家',
            'city' => '縣市',
            'district' => '鄉鎮區',
            'address' => '地址',
            'postal_code' => '郵遞區號',
            default => $key,
        };
    }
}
