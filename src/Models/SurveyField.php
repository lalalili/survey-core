<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Support\FieldKeyGenerator;

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
        'options_json',
        'sort_order',
        'show_if_field_key',
        'show_if_value',
        'page',
    ];

    protected function casts(): array
    {
        return [
            'type' => SurveyFieldType::class,
            'is_required' => 'boolean',
            'is_hidden' => 'boolean',
            'is_personalized' => 'boolean',
            'validation_rules' => 'array',
            'options_json' => 'array',
            'sort_order' => 'integer',
            'page' => 'integer',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    /**
     * Return valid option keys (the keys of the key→label map stored by KeyValue component).
     *
     * @return array<int, string>
     */
    public function optionValues(): array
    {
        if (empty($this->options_json)) {
            return [];
        }

        return array_keys($this->options_json);
    }

    /**
     * Whether this field should be shown given the current answer set.
     * A field with no condition is always visible.
     *
     * @param  array<string, mixed>  $answers  answers keyed by field_key
     */
    public function isConditionallyVisible(array $answers): bool
    {
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
        if (! $this->exists) {
            if (empty($this->field_key)) {
                $this->field_key = FieldKeyGenerator::generate($this->label ?? '');
            }

            if ($this->type === SurveyFieldType::Hidden) {
                $this->is_hidden = true;
            }
        }

        return parent::save($options);
    }
}
