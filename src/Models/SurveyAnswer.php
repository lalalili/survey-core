<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $survey_response_id
 * @property int $survey_field_id
 * @property string|null $answer_text
 * @property array<string, mixed>|null $answer_json
 * @property string|null $snapshot_field_key
 * @property string|null $snapshot_field_label
 * @property string|null $snapshot_field_type
 * @property array<array-key, mixed>|null $snapshot_options_json
 * @property array<string, mixed>|null $snapshot_settings_json
 * @property-read SurveyResponse $response
 * @property-read SurveyField $field
 */
class SurveyAnswer extends Model
{
    protected $fillable = [
        'survey_response_id',
        'survey_field_id',
        'answer_text',
        'answer_json',
        'snapshot_field_key',
        'snapshot_field_label',
        'snapshot_field_type',
        'snapshot_options_json',
        'snapshot_settings_json',
    ];

    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
            'snapshot_options_json' => 'array',
            'snapshot_settings_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }

    /**
     * @return BelongsTo<SurveyField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(SurveyField::class, 'survey_field_id');
    }

    public function getValue(): mixed
    {
        return $this->answer_json ?? $this->answer_text;
    }

    public function fieldKey(): string
    {
        return $this->snapshot_field_key ?? $this->field->field_key;
    }

    public function fieldLabel(): string
    {
        return $this->snapshot_field_label ?? $this->field->label;
    }

    public function fieldType(): string
    {
        return $this->snapshot_field_type ?? $this->field->type->value;
    }

    /** @return list<array{id: string|null, label: string, value: string, capacity: int|null, is_hidden: bool, group: string|null}> */
    public function normalizedSnapshotOptions(): array
    {
        if ($this->fieldType() === 'nps') {
            return SurveyField::canonicalNpsOptions();
        }

        if ($this->snapshot_options_json === null) {
            return $this->field->normalizedOptions();
        }

        if (array_is_list($this->snapshot_options_json)) {
            return array_values(collect($this->snapshot_options_json)
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

        return array_values(collect($this->snapshot_options_json)
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

    /** @return array<string, mixed> */
    public function normalizedSnapshotSettings(): array
    {
        return $this->snapshot_settings_json ?? $this->field->settings_json ?? [];
    }
}
