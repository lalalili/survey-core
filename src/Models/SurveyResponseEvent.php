<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $survey_id
 * @property int|null $survey_collector_id
 * @property int|null $survey_response_id
 * @property string $event
 * @property string|null $page_key
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $metadata_json
 * @property-read Survey $survey
 * @property-read SurveyCollector|null $collector
 * @property-read SurveyResponse|null $response
 */
class SurveyResponseEvent extends Model
{
    protected $fillable = [
        'survey_id',
        'survey_collector_id',
        'survey_response_id',
        'event',
        'page_key',
        'occurred_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata_json' => 'array',
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
     * @return BelongsTo<SurveyCollector, $this>
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(SurveyCollector::class, 'survey_collector_id');
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }
}
