<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;

/**
 * @property int $id
 * @property int $survey_id
 * @property int|null $survey_recipient_id
 * @property string $token
 * @property Carbon|null $expires_at
 * @property int|null $max_submissions
 * @property int $used_count
 * @property Carbon|null $last_used_at
 * @property SurveyTokenStatus $status
 * @property-read Survey $survey
 * @property-read SurveyRecipient|null $recipient
 * @property-read Collection<int, SurveyResponse> $responses
 */
class SurveyToken extends Model
{
    protected $fillable = [
        'survey_id',
        'survey_recipient_id',
        'token',
        'expires_at',
        'max_submissions',
        'used_count',
        'last_used_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyTokenStatus::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'max_submissions' => 'integer',
            'used_count' => 'integer',
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
     * @return BelongsTo<SurveyRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(SurveyRecipient::class, 'survey_recipient_id');
    }

    /**
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function isActive(): bool
    {
        return $this->status === SurveyTokenStatus::Active;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_submissions !== null && $this->used_count >= $this->max_submissions;
    }

    public function isUsable(): bool
    {
        return $this->isActive() && ! $this->isExpired() && ! $this->isExhausted();
    }

    public function recordUsage(): void
    {
        $this->increment('used_count');
        $this->update(['last_used_at' => now()]);
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists && empty($this->token)) {
            $this->token = Str::random((int) config('survey-core.token_length', 64));
        }

        return parent::save($options);
    }
}
