<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
