<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Support\Arr;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;
use Lalalili\SurveyCore\Models\Survey;

class SurveyBuilderSurveySettings
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function normalizeSchema(array $schema): array
    {
        $settings = $schema['settings'] ?? [];

        if (! is_array($settings)) {
            return $schema;
        }

        if (! isset($settings['ends_at']) && isset($settings['close_at'])) {
            $settings['ends_at'] = $settings['close_at'];
        }

        unset($settings['close_at']);

        $schema['settings'] = $settings;

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function mergeSurveyAttributesIntoSchema(Survey $survey, array $schema): array
    {
        $settings = array_replace_recursive(
            $survey->settings_json ?? [],
            is_array($schema['settings'] ?? null) ? $schema['settings'] : [],
        );

        $settings['description'] = $survey->description;
        $settings['starts_at'] = $survey->starts_at?->format('Y-m-d\TH:i');
        $settings['ends_at'] = $survey->ends_at?->format('Y-m-d\TH:i')
            ?? Arr::get($settings, 'close_at');
        $settings['max_responses'] = $survey->max_responses;
        $settings['quota_message'] = $survey->quota_message;
        $settings['uniqueness_mode'] = ($survey->uniqueness_mode ?? SurveyUniquenessMode::None)->value;
        $settings['uniqueness_message'] = $survey->uniqueness_message;

        unset($settings['close_at']);

        $schema['settings'] = $settings;

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function surveyAttributesFromSchema(array $schema): array
    {
        $settings = $schema['settings'] ?? [];

        if (! is_array($settings)) {
            $settings = [];
        }

        $attributes = [
            'title' => $schema['title'],
        ];

        if (array_key_exists('description', $settings)) {
            $attributes['description'] = $this->nullableString($settings['description']);
        }

        if (array_key_exists('starts_at', $settings)) {
            $attributes['starts_at'] = $this->nullableString($settings['starts_at']);
        }

        if (array_key_exists('ends_at', $settings) || array_key_exists('close_at', $settings)) {
            $attributes['ends_at'] = $this->nullableString($settings['ends_at'] ?? ($settings['close_at'] ?? null));
        }

        if (array_key_exists('max_responses', $settings)) {
            $maxResponses = $settings['max_responses'];
            $attributes['max_responses'] = $maxResponses === null || $maxResponses === '' ? null : max(1, (int) $maxResponses);
        }

        if (array_key_exists('quota_message', $settings)) {
            $attributes['quota_message'] = $this->nullableString($settings['quota_message']);
        }

        if (array_key_exists('uniqueness_mode', $settings)) {
            $attributes['uniqueness_mode'] = SurveyUniquenessMode::tryFrom((string) $settings['uniqueness_mode'])
                ?? SurveyUniquenessMode::None;
        }

        if (array_key_exists('uniqueness_message', $settings)) {
            $attributes['uniqueness_message'] = $this->nullableString($settings['uniqueness_message']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function settingsJsonFromSchema(array $schema): ?array
    {
        $settings = $schema['settings'] ?? [];

        if (! is_array($settings)) {
            return null;
        }

        unset($settings['description']);
        unset($settings['starts_at']);
        unset($settings['ends_at']);
        unset($settings['close_at']);
        unset($settings['max_responses']);
        unset($settings['quota_message']);
        unset($settings['uniqueness_mode']);
        unset($settings['uniqueness_message']);

        if (! empty($schema['thank_you_branches'])) {
            $settings['thank_you_branches'] = $schema['thank_you_branches'];
        }

        return $settings === [] ? null : $settings;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
