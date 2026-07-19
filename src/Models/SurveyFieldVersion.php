<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $schema_version_id
 * @property int|null $survey_field_id
 * @property string $element_id
 * @property string $field_key
 * @property string $label
 * @property string $type
 * @property array<array-key, mixed>|null $options_json
 * @property array<string, mixed>|null $settings_json
 * @property int $sort_order
 * @property-read SurveySchemaVersion $schemaVersion
 * @property-read SurveyField|null $field
 */
class SurveyFieldVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'schema_version_id',
        'survey_field_id',
        'element_id',
        'field_key',
        'label',
        'type',
        'options_json',
        'settings_json',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options_json' => 'array',
            'settings_json' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<SurveySchemaVersion, $this> */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SurveySchemaVersion::class, 'schema_version_id');
    }

    /** @return BelongsTo<SurveyField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(SurveyField::class, 'survey_field_id');
    }
}
