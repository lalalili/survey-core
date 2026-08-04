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
use Lalalili\SurveyCore\Support\SurveyFileUploadToken;

class ValidateSurveySubmissionAction
{
    public function __construct(
        private readonly SurveyFileUploadToken $uploadToken,
    ) {
    }

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
            if ($f->retired_at !== null) {
                return false;
            }

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
        $validator->after(fn (ValidationValidator $validator) => $this->validateComplexFields($validator, $survey, $activeFields, $visibleAnswers));

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
            SurveyFieldType::Phone => ['regex:'.$this->phonePattern($field)],
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
            SurveyFieldType::Rating => ['integer', 'min:1', 'max:'.max(1, (int) ($field->settings_json['count'] ?? 5))],
            SurveyFieldType::MultipleChoice, SurveyFieldType::MatrixSingle,
            SurveyFieldType::MatrixMulti, SurveyFieldType::Ranking,
            SurveyFieldType::ConstantSum,
            SurveyFieldType::SelectionBased,
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
            'mobile_tw' => ['string', 'regex:'.$this->patternForLocale('tw')],
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
    private function validateComplexFields(ValidationValidator $validator, Survey $survey, Collection $fields, array $answers): void
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
                SurveyFieldType::FileUpload => $this->validateFileUploadAnswer($validator, $survey, $field, (array) $value),
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
        if (! preg_match($this->phonePattern($field), (string) $value)) {
            $validator->errors()->add($field->field_key, $this->phoneMessage($field));
        }

        $this->validateTextRules($validator, $field, (string) $value);
    }

    /**
     * Resolve the validation regex for a phone field, honouring a per-field
     * `settings_json['phone_locale']` override before the configured default.
     */
    private function phonePattern(SurveyField $field): string
    {
        return $this->patternForLocale($this->phoneLocale($field));
    }

    private function patternForLocale(string $locale): string
    {
        $patterns = config('survey-core.phone.patterns', []);

        return $patterns[$locale] ?? $patterns['tw'] ?? '/^09\d{8}$/';
    }

    private function phoneMessage(SurveyField $field): string
    {
        $messages = config('survey-core.phone.messages', []);
        $message = $messages[$this->phoneLocale($field)] ?? $messages['tw'] ?? '請輸入有效的電話號碼。';

        return "「{$field->label}」{$message}";
    }

    private function phoneLocale(SurveyField $field): string
    {
        $locale = $field->settings_json['phone_locale'] ?? config('survey-core.phone.default_locale', 'tw');

        return is_string($locale) && $locale !== '' ? $locale : 'tw';
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

        $cascadeData = is_array($field->settings_json['cascade_data'] ?? null) ? $field->settings_json['cascade_data'] : [];
        $currentNodes = $cascadeData;

        foreach ($levels as $level) {
            $levelId = (string) ($level['id'] ?? '');
            if ($levelId === '') {
                continue;
            }

            $levelLabel = (string) ($level['label'] ?? $levelId);
            $answer = trim((string) ($value[$levelId] ?? ''));

            if ($answer === '') {
                if ($field->is_required) {
                    $validator->errors()->add($field->field_key, "「{$field->label}」的「{$levelLabel}」尚未選擇。");
                }

                // 空層之後的深層選擇沒有合法選項集合可比對
                $currentNodes = [];

                continue;
            }

            if ($cascadeData === []) {
                continue;
            }

            $matched = collect($currentNodes)
                ->filter(fn (mixed $node): bool => is_array($node))
                ->first(fn (array $node): bool => (string) ($node['id'] ?? $node['label'] ?? '') === $answer);

            if ($matched === null) {
                $validator->errors()->add($field->field_key, "「{$field->label}」的「{$levelLabel}」包含不存在的選項，請重新選擇。");
                $currentNodes = [];

                continue;
            }

            $currentNodes = is_array($matched['children'] ?? null) ? $matched['children'] : [];
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
    private function validateFileUploadAnswer(ValidationValidator $validator, Survey $survey, SurveyField $field, array $value): void
    {
        if ($field->is_required && empty($value['media_id'])) {
            $validator->errors()->add($field->field_key, "「{$field->label}」請上傳檔案。");
        }

        $media = empty($value['media_id']) ? null : $this->uploadToken->resolve($value, $survey, $field);

        if (! empty($value['media_id']) && $media === null) {
            $validator->errors()->add($field->field_key, "「{$field->label}」上傳的檔案不存在，請重新上傳。");
        }

        $maxSizeMb = (int) ($field->settings_json['max_size_mb'] ?? 0);
        if ($maxSizeMb > 0 && $media !== null && $media->size > $maxSizeMb * 1024 * 1024) {
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
        $characterCount = mb_strlen($value);

        if (isset($rules['min_length']) && $characterCount < (int) $rules['min_length']) {
            $minimumLength = (int) $rules['min_length'];
            $remainingLength = $minimumLength - $characterCount;

            $validator->errors()->add($field->field_key, (string) ($rules['pattern_label'] ?? "「{$field->label}」目前輸入 {$characterCount} 個字，還需要 {$remainingLength} 個字。"));
        }

        $minimumChineseLength = (int) ($rules['min_chinese_length'] ?? 0);
        $chineseCharacterCount = preg_match_all('/\p{Han}/u', $value) ?: 0;

        if (
            in_array($field->type, [SurveyFieldType::ShortText, SurveyFieldType::LongText], true)
            && $minimumChineseLength > 0
            && $chineseCharacterCount < $minimumChineseLength
        ) {
            $remainingChineseLength = $minimumChineseLength - $chineseCharacterCount;

            $validator->errors()->add($field->field_key, "「{$field->label}」目前有 {$chineseCharacterCount} 個中文字，還需要 {$remainingChineseLength} 個中文字。");
        }

        if (isset($rules['max_length']) && $characterCount > (int) $rules['max_length']) {
            $maximumLength = (int) $rules['max_length'];
            $excessLength = $characterCount - $maximumLength;

            $validator->errors()->add($field->field_key, "「{$field->label}」目前輸入 {$characterCount} 個字，請刪除 {$excessLength} 個字。");
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
