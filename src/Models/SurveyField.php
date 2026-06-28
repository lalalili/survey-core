<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Support\ConditionGroupEvaluator;
use Lalalili\SurveyCore\Support\FieldKeyGenerator;

/**
 * @property int $id
 * @property int $survey_id
 * @property SurveyFieldType $type
 * @property string $label
 * @property string|null $description
 * @property bool $is_required
 * @property bool $is_hidden
 * @property bool $is_personalized
 * @property string|null $personalized_key
 * @property string $field_key
 * @property string|null $placeholder
 * @property string|null $default_value
 * @property array<string, mixed>|null $validation_rules
 * @property array<string, mixed>|null $settings_json
 * @property array<array-key, mixed>|null $options_json
 * @property int $sort_order
 * @property string|null $show_if_field_key
 * @property string|null $show_if_value
 * @property int|null $survey_page_id
 * @property-read Survey $survey
 * @property-read SurveyPage|null $surveyPage
 * @property-read Collection<int, SurveyAnswer> $answers
 */
class SurveyField extends Model
{
    protected $fillable = [
        'survey_id',
        'type',
        'label',
        'description',
        'is_required',
        'is_hidden',
        'is_personalized',
        'personalized_key',
        'field_key',
        'placeholder',
        'default_value',
        'validation_rules',
        'settings_json',
        'options_json',
        'sort_order',
        'show_if_field_key',
        'show_if_value',
        'survey_page_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => SurveyFieldType::class,
            'is_required' => 'boolean',
            'is_hidden' => 'boolean',
            'is_personalized' => 'boolean',
            'validation_rules' => 'array',
            'settings_json' => 'array',
            'options_json' => 'array',
            'sort_order' => 'integer',
            'survey_page_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return BelongsTo<SurveyPage, $this>
     */
    public function surveyPage(): BelongsTo
    {
        return $this->belongsTo(SurveyPage::class, 'survey_page_id');
    }

    /**
     * @return HasMany<SurveyAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    /**
     * Return [value => label] dict for public rendering, normalising both storage formats:
     * - list  : [{id, label, value, ...}]  (builder-managed, current)
     * - dict  : {value: label}             (legacy / import)
     *
     * @return array<string, string>
     */
    public function optionsForDisplay(): array
    {
        if (empty($this->options_json)) {
            return [];
        }

        if (array_is_list($this->options_json)) {
            $result = [];
            foreach ($this->options_json as $opt) {
                if ((bool) ($opt['is_hidden'] ?? false)) {
                    continue;
                }

                $result[(string) ($opt['value'] ?? '')] = (string) ($opt['label'] ?? '');
            }

            return $result;
        }

        return array_map('strval', $this->options_json);
    }

    /**
     * Return valid option keys as strings (the keys of the key→label map stored by KeyValue component).
     * PHP's json_decode converts numeric-looking keys to int, so we always cast to string.
     *
     * @return array<int, string>
     */
    public function optionValues(): array
    {
        if (empty($this->options_json)) {
            return [];
        }

        if (array_is_list($this->options_json)) {
            return collect($this->options_json)
                ->pluck('value')
                ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
                ->map(fn (mixed $value): string => (string) $value)
                ->values()
                ->all();
        }

        return array_map('strval', array_keys($this->options_json));
    }

    /**
     * @return list<array{id: string|null, label: string, value: string, capacity: int|null, is_hidden: bool, group: string|null}>
     */
    public function normalizedOptions(): array
    {
        if (empty($this->options_json)) {
            return [];
        }

        if (array_is_list($this->options_json)) {
            return array_values(collect($this->options_json)
                ->map(fn (mixed $option): array => [
                    'id' => data_get($option, 'id') !== null ? (string) data_get($option, 'id') : null,
                    'label' => (string) data_get($option, 'label', ''),
                    'value' => (string) data_get($option, 'value', ''),
                    'capacity' => data_get($option, 'capacity') !== null ? (int) data_get($option, 'capacity') : null,
                    'is_hidden' => (bool) data_get($option, 'is_hidden', false),
                    'group' => data_get($option, 'group') !== null && (string) data_get($option, 'group') !== ''
                        ? (string) data_get($option, 'group')
                        : null,
                ])
                ->filter(fn (array $option): bool => $option['value'] !== '')
                ->values()
                ->all());
        }

        return array_values(collect($this->options_json)
            ->map(fn (mixed $label, mixed $value): array => [
                'id' => null,
                'label' => (string) $label,
                'value' => (string) $value,
                'capacity' => null,
                'is_hidden' => false,
                'group' => null,
            ])
            ->values()
            ->all());
    }

    /**
     * Options grouped for public rendering, honouring optional per-option
     * `group` labels. Within-group order is shuffled when `randomize_options`
     * is on; the order of the groups themselves is shuffled when
     * `randomize_option_groups` is on. Ungrouped options share a leading group
     * with an empty label. Display-only.
     *
     * @return list<array{label: string, options: list<array{id: string|null, label: string, value: string, capacity: int|null, is_hidden: bool, group: string|null}>}>
     */
    public function displayOptionGroups(?int $seed = null): array
    {
        $groups = [];

        foreach ($this->normalizedOptions() as $option) {
            $label = $option['group'] ?? '';
            $groups[$label] ??= [];
            $groups[$label][] = $option;
        }

        $randomizeWithin = (bool) ($this->settings_json['randomize_options'] ?? false);

        $result = [];
        foreach ($groups as $label => $options) {
            $result[] = [
                'label' => (string) $label,
                'options' => $randomizeWithin ? $this->seededShuffle($options, $seed) : $options,
            ];
        }

        if ((bool) ($this->settings_json['randomize_option_groups'] ?? false)) {
            $result = $this->seededShuffle($result, $seed !== null ? $seed ^ 0x5f3759df : null);
        }

        return $result;
    }

    /**
     * Flat options for public rendering — a group-aware flatten of
     * displayOptionGroups(), so options stay contiguous within their group and
     * honour both within-group and group-order randomization. Each option keeps
     * its `group` label so the view can emit group headings. Display-only —
     * never use this for validation, analytics, or submission handling (those
     * rely on the stable order from normalizedOptions()).
     *
     * @return list<array{id: string|null, label: string, value: string, capacity: int|null, is_hidden: bool, group: string|null}>
     */
    public function displayOptions(?int $seed = null): array
    {
        $flat = [];

        foreach ($this->displayOptionGroups($seed) as $group) {
            foreach ($group['options'] as $option) {
                $flat[] = $option;
            }
        }

        return $flat;
    }

    /**
     * Matrix rows for public rendering, optionally shuffled when the
     * `randomize_rows` setting is enabled. Display-only.
     *
     * @return list<array<string, mixed>>
     */
    public function displayMatrixRows(?int $seed = null): array
    {
        $rows = array_values($this->settings_json['matrix_rows'] ?? []);

        if (! (bool) ($this->settings_json['randomize_rows'] ?? false)) {
            return $rows;
        }

        return $this->seededShuffle($rows, $seed);
    }

    /**
     * Deterministic Fisher–Yates shuffle: the same seed and field always
     * produce the same order, so a respondent sees a stable layout across page
     * navigation and re-renders while different respondents see different orders.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function seededShuffle(array $items, ?int $seed): array
    {
        if (count($items) <= 1) {
            return $items;
        }

        mt_srand(($seed ?? 0) ^ crc32((string) ($this->field_key ?? $this->id)));

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        mt_srand();

        return array_values($items);
    }

    /**
     * Return the configured jump action for a specific submitted option value.
     * Only meaningful for list-format options_json (builder-managed fields).
     *
     * @return array{type: string, target_page_id?: string}|null
     */
    public function getOptionAction(string $value): ?array
    {
        if (empty($this->options_json) || ! array_is_list($this->options_json)) {
            return null;
        }

        foreach ($this->options_json as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                $action = $option['action'] ?? null;

                return is_array($action) && isset($action['type']) ? $action : null;
            }
        }

        return null;
    }

    /**
     * Whether this field should be shown given the current answer set.
     * A field with no condition is always visible.
     *
     * @param  array<string, mixed>  $answers  answers keyed by field_key
     */
    public function isConditionallyVisible(array $answers): bool
    {
        $showIf = $this->settings_json['show_if'] ?? null;
        if (is_array($showIf)) {
            return ConditionGroupEvaluator::passes($showIf, $answers);
        }

        if (! $this->show_if_field_key) {
            return true;
        }

        $current = $answers[$this->show_if_field_key] ?? null;

        if (is_array($current)) {
            return in_array($this->show_if_value, $current, true);
        }

        return (string) $current === (string) $this->show_if_value;
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists && empty($this->field_key)) {
            $this->field_key = FieldKeyGenerator::generate($this->label ?? '');
        }

        // type=Hidden always forces is_hidden
        if ($this->type === SurveyFieldType::Hidden) {
            $this->is_hidden = true;
        }

        // is_personalized is derived: true only when hidden AND a personalized_key is set
        $this->is_personalized = $this->is_hidden && ! empty($this->personalized_key);

        return parent::save($options);
    }
}
