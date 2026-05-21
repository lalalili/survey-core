<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $survey_response_id
 * @property string $type
 * @property string|null $version
 * @property Carbon $accepted_at
 * @property array<string, mixed>|null $metadata_json
 * @property-read SurveyResponse $response
 */
class SurveyResponseConsent extends Model
{
    protected $fillable = [
        'survey_response_id',
        'type',
        'version',
        'accepted_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }
}
