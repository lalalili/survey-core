<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Lalalili\AudienceCore\Concerns\LogsModelActivity;
use LogicException;

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
 * @property Carbon|null $deleted_at
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyTriggerDispatch> $dispatches
 * @property-read Collection<int, SurveyTriggerRuleRun> $runs
 */
class SurveyTriggerRule extends Model
{
    use LogsModelActivity;
    use SoftDeletes;

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
        static::deleting(function (self $rule): void {
            if ($rule->isForceDeleting()) {
                return;
            }

            if ($rule->is_active || $rule->schedule_enabled) {
                throw new LogicException('啟用中或仍開啟排程的發送設定不可刪除，請先停用規則與排程。');
            }
        });

        // sqlsrv 上 dispatches.survey_trigger_rule_id FK 為 NO ACTION（multiple cascade paths 限制），
        // 永久刪除規則前先清派送紀錄；軟刪除則保留派送歷程供還原與稽核。
        static::deleting(function (self $rule): void {
            if (! $rule->isForceDeleting()) {
                return;
            }

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
