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
    ];

    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
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
}
