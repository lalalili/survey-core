<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;

/**
 * @property int $id
 * @property int|null $survey_trigger_action_preset_id
 * @property int|null $survey_trigger_dispatch_id
 * @property string $action_key
 * @property string $action_type
 * @property string $mode
 * @property string $profile
 * @property SurveyTriggerActionAttemptStatus $status
 * @property string|null $ticket_no
 * @property string $endpoint
 * @property array<string, mixed> $request_parameters
 * @property string $request_body
 * @property int|null $response_http_status
 * @property array<string, mixed>|null $response_headers
 * @property string|null $response_body
 * @property array<string, mixed>|null $parsed_response
 * @property string|null $error
 * @property int|null $duration_ms
 * @property int|null $initiated_by
 * @property Carbon|null $sent_at
 * @property-read SurveyTriggerActionPreset|null $preset
 * @property-read SurveyTriggerDispatch|null $dispatch
 */
class SurveyTriggerActionAttempt extends Model
{
    protected $fillable = [
        'survey_trigger_action_preset_id',
        'survey_trigger_dispatch_id',
        'action_key',
        'action_type',
        'mode',
        'profile',
        'status',
        'ticket_no',
        'endpoint',
        'request_parameters',
        'request_body',
        'response_http_status',
        'response_headers',
        'response_body',
        'parsed_response',
        'error',
        'duration_ms',
        'initiated_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyTriggerActionAttemptStatus::class,
            'request_parameters' => 'encrypted:array',
            'request_body' => 'encrypted',
            'response_http_status' => 'integer',
            'response_headers' => 'encrypted:array',
            'response_body' => 'encrypted',
            'parsed_response' => 'encrypted:array',
            'duration_ms' => 'integer',
            'initiated_by' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SurveyTriggerActionPreset, $this>
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(SurveyTriggerActionPreset::class, 'survey_trigger_action_preset_id');
    }

    /**
     * @return BelongsTo<SurveyTriggerDispatch, $this>
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(SurveyTriggerDispatch::class, 'survey_trigger_dispatch_id');
    }
}
