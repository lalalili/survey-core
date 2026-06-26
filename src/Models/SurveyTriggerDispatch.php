<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;

/**
 * @property int $id
 * @property int $survey_trigger_rule_id
 * @property int $survey_response_id
 * @property TriggerDispatchStatus $status
 * @property array<string, mixed>|null $payload_json
 * @property array<string, mixed>|null $response_json
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $dispatched_at
 * @property-read SurveyTriggerRule $rule
 * @property-read SurveyResponse $response
 */
class SurveyTriggerDispatch extends Model
{
    protected $fillable = [
        'survey_trigger_rule_id',
        'survey_response_id',
        'status',
        'payload_json',
        'response_json',
        'error',
        'attempts',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TriggerDispatchStatus::class,
            'payload_json' => 'array',
            'response_json' => 'array',
            'attempts' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SurveyTriggerRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SurveyTriggerRule::class, 'survey_trigger_rule_id');
    }

    /**
     * @return BelongsTo<SurveyResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }
}
