<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\TriggerRunStatus;
use Lalalili\SurveyCore\Enums\TriggerRunType;

/**
 * @property int $id
 * @property int $survey_trigger_rule_id
 * @property TriggerRunType $trigger_type
 * @property TriggerRunStatus $status
 * @property int $scanned_count
 * @property int $matched_count
 * @property int $dispatched_count
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read SurveyTriggerRule $rule
 */
class SurveyTriggerRuleRun extends Model
{
    protected $fillable = [
        'survey_trigger_rule_id',
        'trigger_type',
        'status',
        'scanned_count',
        'matched_count',
        'dispatched_count',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => TriggerRunType::class,
            'status' => TriggerRunStatus::class,
            'scanned_count' => 'integer',
            'matched_count' => 'integer',
            'dispatched_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SurveyTriggerRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SurveyTriggerRule::class, 'survey_trigger_rule_id');
    }
}
