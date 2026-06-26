<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $survey_id
 * @property string $key
 * @property string $label
 * @property int $initial_value
 * @property string|null $output_format
 * @property array<string, mixed>|null $grade_map_json
 * @property-read Survey $survey
 */
class SurveyCalculation extends Model
{
    protected $fillable = [
        'survey_id',
        'key',
        'label',
        'initial_value',
        'output_format',
        'grade_map_json',
    ];

    protected function casts(): array
    {
        return [
            'initial_value' => 'integer',
            'grade_map_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
