<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $survey_id
 * @property string $type
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings_json
 * @property array<string, mixed>|null $tracking_json
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyResponse> $responses
 * @property-read Collection<int, SurveyResponseEvent> $events
 */
class SurveyCollector extends Model
{
    protected $fillable = [
        'survey_id',
        'type',
        'name',
        'slug',
        'settings_json',
        'tracking_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
            'tracking_json' => 'array',
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
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'survey_collector_id');
    }

    /**
     * @return HasMany<SurveyResponseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SurveyResponseEvent::class, 'survey_collector_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists && empty($this->slug)) {
            $this->slug = Str::slug($this->name).'-'.Str::lower(Str::random(8));
        }

        return parent::save($options);
    }
}
