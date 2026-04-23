<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyAnswer extends Model
{
    protected $fillable = [
        'survey_response_id',
        'survey_field_id',
        'answer_text',
        'answer_json',
    ];

    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
        ];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(SurveyField::class, 'survey_field_id');
    }

    public function getValue(): mixed
    {
        return $this->answer_json ?? $this->answer_text;
    }
}
