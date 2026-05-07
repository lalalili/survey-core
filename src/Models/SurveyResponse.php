<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $survey_id
 * @property int|null $survey_recipient_id
 * @property int|null $survey_token_id
 * @property Carbon|null $submitted_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $calculations_json
 * @property SurveyResponseCompletionStatus $completion_status
 * @property SurveyResponseQualityStatus $quality_status
 * @property array<string, mixed>|null $quality_flags_json
 * @property string|null $notes
 * @property-read Survey $survey
 * @property-read SurveyRecipient|null $recipient
 * @property-read SurveyToken|null $token
 * @property-read Collection<int, SurveyAnswer> $answers
 * @property-read Collection<int, SurveyTag> $tags
 */
class SurveyResponse extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'survey_id',
        'survey_recipient_id',
        'survey_token_id',
        'submitted_at',
        'ip',
        'user_agent',
        'calculations_json',
        'completion_status',
        'quality_status',
        'quality_flags_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'completion_status' => SurveyResponseCompletionStatus::class,
            'quality_status' => SurveyResponseQualityStatus::class,
            'quality_flags_json' => 'array',
            'calculations_json' => 'array',
            'submitted_at' => 'datetime',
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
     * @return BelongsTo<SurveyToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(SurveyToken::class, 'survey_token_id');
    }

    /**
     * @return HasMany<SurveyAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    /**
     * @return BelongsToMany<SurveyTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SurveyTag::class, 'survey_response_tag');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('survey_files');
    }
}
