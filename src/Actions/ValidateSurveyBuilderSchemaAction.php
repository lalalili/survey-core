<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;

class ValidateSurveyBuilderSchemaAction
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function execute(array $schema): array
    {
        $supportedTypes = collect(SurveyFieldType::cases())
            ->reject(fn (SurveyFieldType $type): bool => $type === SurveyFieldType::Hidden)
            ->map(fn (SurveyFieldType $type): string => $type->value)
            ->all();

        $validator = Validator::make($schema, [
            'id'                                              => ['nullable'],
            'title'                                           => ['required', 'string', 'max:255'],
            'status'                                          => ['nullable', 'string'],
            'version'                                         => ['nullable', 'integer', 'min:1'],
            'settings'                                        => ['nullable', 'array'],
            'settings.progress'                               => ['nullable', 'array'],
            'settings.progress.mode'                          => ['nullable', 'string', Rule::in(['none', 'bar', 'steps', 'percent'])],
            'settings.progress.show_estimated_time'           => ['nullable', 'boolean'],
            'settings.show_question_numbers'                  => ['nullable', 'boolean'],
            'settings.allow_back'                             => ['nullable', 'boolean'],
            'settings.language'                               => ['nullable', 'string', Rule::in(['zh-TW', 'zh-CN', 'en'])],
            'settings.terms_text'                             => ['nullable', 'string'],
            'settings.response_number'                        => ['nullable', 'boolean'],
            'settings.notify_emails'                          => ['nullable', 'string', 'max:2000'],
            'settings.password'                               => ['nullable', 'string', 'max:100'],
            'settings.close_at'                               => ['nullable', 'string'],
            'settings.max_responses'                          => ['nullable', 'integer', 'min:1'],
            'settings.anomaly'                                => ['nullable', 'array'],
            'settings.anomaly.min_seconds'                    => ['nullable', 'integer', 'min:0'],
            'settings.anomaly.detect_duplicate'               => ['nullable', 'string', Rule::in(['none', 'cookie', 'ip', 'both'])],
            'settings.anomaly.turnstile'                      => ['nullable', 'boolean'],
            'theme_id'                                        => ['nullable', 'integer'],
            'theme_overrides'                                 => ['nullable', 'array'],
            'calculations'                                    => ['nullable', 'array'],
            'calculations.*.id'                               => ['nullable', 'string', 'max:100'],
            'calculations.*.key'                              => ['required', 'string', 'max:100'],
            'calculations.*.label'                            => ['required', 'string', 'max:255'],
            'calculations.*.initial_value'                    => ['nullable', 'integer'],
            'calculations.*.output_format'                    => ['nullable', 'string', Rule::in(['number', 'grade', 'label'])],
            'calculations.*.grade_map_json'                   => ['nullable', 'array'],
            'thank_you_branches'                              => ['nullable', 'array'],
            'thank_you_branches.*.condition'                  => ['required_with:thank_you_branches', 'array'],
            'thank_you_branches.*.action'                     => ['nullable', 'array'],
            'thank_you_branches.*.page_id'                    => ['nullable', 'string', 'max:100'],
            'pages'                                           => ['required', 'array', 'min:1'],
            'pages.*.id'                                      => ['required', 'string', 'max:100'],
            'pages.*.kind'                                    => ['nullable', 'string', Rule::in(array_map(fn (SurveyPageKind $kind): string => $kind->value, SurveyPageKind::cases()))],
            'pages.*.title'                                   => ['nullable', 'string', 'max:255'],
            'pages.*.welcome_settings'                        => ['nullable', 'array'],
            'pages.*.welcome_settings.enabled'                => ['nullable', 'boolean'],
            'pages.*.welcome_settings.cta_label'              => ['nullable', 'string', 'max:100'],
            'pages.*.welcome_settings.subtitle'               => ['nullable', 'string', 'max:500'],
            'pages.*.welcome_settings.content'                => ['nullable', 'string'],
            'pages.*.welcome_settings.estimated_time_minutes' => ['nullable', 'integer', 'min:0'],
            'pages.*.thank_you_settings'                      => ['nullable', 'array'],
            'pages.*.thank_you_settings.enabled'              => ['nullable', 'boolean'],
            'pages.*.thank_you_settings.message'              => ['nullable', 'string'],
            'pages.*.thank_you_settings.redirect_url'         => ['nullable', 'string', 'max:2048'],
            'pages.*.jump_rules'                              => ['nullable', 'array'],
            'pages.*.elements'                                => ['present', 'array'],
            'pages.*.elements.*.id'                           => ['required', 'string', 'max:100'],
            'pages.*.elements.*.type'                         => ['required', 'string', Rule::in($supportedTypes)],
            'pages.*.elements.*.field_key'                    => ['nullable', 'string', 'max:100'],
            'pages.*.elements.*.label'                        => ['required', 'string', 'max:255'],
            'pages.*.elements.*.description'                  => ['nullable', 'string'],
            'pages.*.elements.*.required'                     => ['required', 'boolean'],
            'pages.*.elements.*.placeholder'                  => ['nullable', 'string', 'max:255'],
            'pages.*.elements.*.options'                      => ['present', 'array'],
            'pages.*.elements.*.settings'                     => ['present', 'array'],
            'pages.*.elements.*.matrix_rows'                  => ['nullable', 'array'],
            'pages.*.elements.*.matrix_rows.*.id'             => ['required_with:pages.*.elements.*.matrix_rows', 'string', 'max:100'],
            'pages.*.elements.*.matrix_rows.*.label'          => ['required_with:pages.*.elements.*.matrix_rows', 'string', 'max:255'],
            'pages.*.elements.*.matrix_cols'                  => ['nullable', 'array'],
            'pages.*.elements.*.matrix_cols.*.id'             => ['required_with:pages.*.elements.*.matrix_cols', 'string', 'max:100'],
            'pages.*.elements.*.matrix_cols.*.label'          => ['required_with:pages.*.elements.*.matrix_cols', 'string', 'max:255'],
            'pages.*.elements.*.cascade_levels'               => ['nullable', 'array'],
            'pages.*.elements.*.cascade_levels.*.id'          => ['required_with:pages.*.elements.*.cascade_levels', 'string', 'max:100'],
            'pages.*.elements.*.cascade_levels.*.label'       => ['required_with:pages.*.elements.*.cascade_levels', 'string', 'max:255'],
            'pages.*.elements.*.cascade_data'                 => ['nullable', 'array'],
            'pages.*.elements.*.validation_rules'             => ['nullable', 'array'],
            'pages.*.elements.*.show_if'                      => ['nullable', 'array'],
            'pages.*.elements.*.show_if_field_key'            => ['nullable', 'string', 'max:100'],
            'pages.*.elements.*.show_if_value'                => ['nullable', 'string', 'max:255'],
            'pages.*.elements.*.is_hidden'                    => ['nullable', 'boolean'],
            'pages.*.elements.*.personalized_key'             => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw new SurveyValidationException($validator->errors()->toArray());
        }

        $normalized = $validator->validated();
        $this->validateElements($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateElements(array $schema): void
    {
        $errors = [];
        $fieldKeys = [];
        $pageIds = array_column($schema['pages'], 'id');
        $welcomeIndexes = [];
        $thankYouIndexes = [];

        // Collect page indices for forward-jump validation
        $pageIndexMap = array_flip($pageIds);

        foreach ($schema['pages'] as $pageIndex => $page) {
            $pageKind = $page['kind'] ?? SurveyPageKind::Question->value;
            if ($pageKind === SurveyPageKind::Welcome->value) {
                $welcomeIndexes[] = $pageIndex;
            }
            if ($pageKind === SurveyPageKind::ThankYou->value) {
                $thankYouIndexes[] = $pageIndex;
            }

            if ($pageKind === SurveyPageKind::Question->value && blank($page['title'] ?? null)) {
                $errors["pages.{$pageIndex}.title"][] = 'The page title is required.';
            }

            foreach ($page['elements'] as $elementIndex => $element) {
                $type = SurveyFieldType::from($element['type']);
                $path = "pages.{$pageIndex}.elements.{$elementIndex}";

                if ($pageKind !== SurveyPageKind::Question->value && (bool) ($element['required'] ?? false)) {
                    $errors["{$path}.required"][] = 'Welcome and thank-you pages cannot contain required questions.';
                }

                if (! $type->isContentBlock()) {
                    $fieldKey = (string) ($element['field_key'] ?? '');
                    if ($fieldKey === '') {
                        $errors["{$path}.field_key"][] = 'The field key is required.';
                    } elseif (in_array($fieldKey, $fieldKeys, true)) {
                        $errors["{$path}.field_key"][] = 'The field key must be unique.';
                    }

                    $fieldKeys[] = $fieldKey;
                }

                if (! $type->requiresOptions()) {
                    if (in_array($type, [SurveyFieldType::MatrixSingle, SurveyFieldType::MatrixMulti], true)) {
                        if (empty($element['matrix_rows'] ?? [])) {
                            $errors["{$path}.matrix_rows"][] = 'At least one matrix row is required.';
                        }

                        if (empty($element['matrix_cols'] ?? [])) {
                            $errors["{$path}.matrix_cols"][] = 'At least one matrix column is required.';
                        }
                    }

                    continue;
                }

                $options = Arr::where($element['options'] ?? [], fn (mixed $option): bool => is_array($option));

                if ($options === []) {
                    $errors["{$path}.options"][] = 'At least one option is required.';
                    continue;
                }

                $supportsJump = in_array($type, [SurveyFieldType::SingleChoice, SurveyFieldType::Select], true);

                foreach ($options as $optionIndex => $option) {
                    if (blank($option['id'] ?? null)) {
                        $errors["{$path}.options.{$optionIndex}.id"][] = 'The option id is required.';
                    }

                    if (blank($option['label'] ?? null)) {
                        $errors["{$path}.options.{$optionIndex}.label"][] = 'The option label is required.';
                    }

                    $action = $option['action'] ?? null;
                    if ($action === null) {
                        continue;
                    }

                    if (! $supportsJump) {
                        $errors["{$path}.options.{$optionIndex}.action"][] = 'Jump actions are only supported on single_choice and select questions.';
                        continue;
                    }

                    $allowedTypes = ['next_page', 'go_to_page', 'end_survey'];
                    $actionType = $action['type'] ?? '';

                    if (! in_array($actionType, $allowedTypes, true)) {
                        $errors["{$path}.options.{$optionIndex}.action.type"][] = 'Invalid jump action type.';
                        continue;
                    }

                    if ($actionType === 'go_to_page') {
                        $targetId = $action['target_page_id'] ?? '';
                        if (! in_array($targetId, $pageIds, true)) {
                            $errors["{$path}.options.{$optionIndex}.action.target_page_id"][] = 'Target page does not exist.';
                        } elseif (($pageIndexMap[$targetId] ?? PHP_INT_MAX) <= $pageIndex) {
                            $errors["{$path}.options.{$optionIndex}.action.target_page_id"][] = 'Backward jumps are not allowed. Target page must come after the current page.';
                        }
                    }
                }
            }
        }

        if (count($welcomeIndexes) > 1) {
            $errors['pages'][] = 'Only one welcome page is allowed.';
        }

        if ($welcomeIndexes !== [] && $welcomeIndexes[0] !== 0) {
            $errors['pages.'.$welcomeIndexes[0].'.kind'][] = 'The welcome page must be the first page.';
        }

        $lastPageIndex = count($schema['pages']) - 1;
        foreach ($thankYouIndexes as $thankYouIndex) {
            if ($thankYouIndex < $lastPageIndex && ($schema['pages'][$thankYouIndex + 1]['kind'] ?? SurveyPageKind::Question->value) !== SurveyPageKind::ThankYou->value) {
                $errors['pages.'.$thankYouIndex.'.kind'][] = 'Thank-you pages must be grouped at the end.';
            }
        }

        foreach (($schema['thank_you_branches'] ?? []) as $branchIndex => $branch) {
            $pageId = $branch['page_id'] ?? data_get($branch, 'action.target_page_id');
            if ($pageId !== null && ! in_array($pageId, $pageIds, true)) {
                $errors["thank_you_branches.{$branchIndex}.page_id"][] = 'Target thank-you page does not exist.';
            }
        }

        if ($errors !== []) {
            throw new SurveyValidationException($errors);
        }
    }
}
