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
 * @property Carbon|null $last_triggered_at
 * @property int $triggered_count
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyTriggerDispatch> $dispatches
 */
class SurveyTriggerRule extends Model
{
    protected $fillable = [
        'survey_id',
        'name',
        'is_active',
        'rule_tree_json',
        'actions_json',
        'last_triggered_at',
        'triggered_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    /**
     * @return HasMany<SurveyTriggerDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(SurveyTriggerDispatch::class);
    }
}
