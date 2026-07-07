<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $survey_id
 * @property string $name
 * @property bool $is_active
 * @property array<string, mixed> $rule_tree_json
 * @property array<int, array<string, mixed>> $actions_json
 * @property bool $schedule_enabled
 * @property string|null $schedule_time
 * @property int $schedule_window_days
 * @property Carbon|null $last_scheduled_run_at
 * @property Carbon|null $last_triggered_at
 * @property int $triggered_count
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyTriggerDispatch> $dispatches
 * @property-read Collection<int, SurveyTriggerRuleRun> $runs
 */
class SurveyTriggerRule extends Model
{
    protected $fillable = [
        'survey_id',
        'name',
        'is_active',
        'schedule_enabled',
        'schedule_time',
        'schedule_window_days',
        'last_scheduled_run_at',
        'rule_tree_json',
        'actions_json',
        'last_triggered_at',
        'triggered_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'schedule_enabled' => 'boolean',
            'schedule_window_days' => 'integer',
            'last_scheduled_run_at' => 'datetime',
            'rule_tree_json' => 'array',
            'actions_json' => 'array',
            'last_triggered_at' => 'datetime',
            'triggered_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    protected static function booted(): void
    {
        // sqlsrv 上 dispatches.survey_trigger_rule_id FK 為 NO ACTION（multiple cascade paths 限制），
        // 刪規則前先清派送紀錄；其他 driver 有 DB cascade，重複刪除無害。
        static::deleting(function (self $rule): void {
            $rule->dispatches()->delete();
        });
    }

    /**
     * @return HasMany<SurveyTriggerDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(SurveyTriggerDispatch::class);
    }

    /**
     * @return HasMany<SurveyTriggerRuleRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SurveyTriggerRuleRun::class);
    }
}
