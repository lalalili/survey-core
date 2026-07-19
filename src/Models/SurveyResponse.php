<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string|null $response_number
 * @property int $survey_id
 * @property int|null $survey_recipient_id
 * @property int|null $survey_token_id
 * @property int|null $survey_collector_id
 * @property int|null $schema_version_id
 * @property Carbon|null $submitted_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $calculations_json
 * @property SurveyResponseCompletionStatus $completion_status
 * @property SurveyResponseQualityStatus $quality_status
 * @property array<string, mixed>|null $quality_flags_json
 * @property string|null $notes
 * @property bool $is_test
 * @property Carbon|null $created_at
 * @property-read Survey $survey
 * @property-read SurveyRecipient|null $recipient
 * @property-read SurveyToken|null $token
 * @property-read SurveyCollector|null $collector
 * @property-read SurveySchemaVersion|null $schemaVersion
 * @property-read Collection<int, SurveyAnswer> $answers
 * @property-read Collection<int, SurveyTag> $tags
 * @property-read Collection<int, SurveyResponseEvent> $events
 * @property-read Collection<int, SurveyResponseConsent> $consents
 */
class SurveyResponse extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    protected $fillable = [
        'survey_id',
        'response_number',
        'survey_recipient_id',
        'survey_token_id',
        'survey_collector_id',
        'schema_version_id',
        'submitted_at',
        'ip',
        'user_agent',
        'calculations_json',
        'completion_status',
        'quality_status',
        'quality_flags_json',
        'notes',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'completion_status' => SurveyResponseCompletionStatus::class,
            'quality_status' => SurveyResponseQualityStatus::class,
            'quality_flags_json' => 'array',
            'calculations_json' => 'array',
            'submitted_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReportable(Builder $query): Builder
    {
        return $query
            ->where('is_test', false)
            ->whereNotNull('submitted_at')
            ->where('quality_status', SurveyResponseQualityStatus::Accepted);
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
     * @return BelongsTo<SurveyCollector, $this>
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(SurveyCollector::class, 'survey_collector_id');
    }

    /** @return BelongsTo<SurveySchemaVersion, $this> */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SurveySchemaVersion::class, 'schema_version_id');
    }

    protected static function booted(): void
    {
        // sqlsrv 上 events.survey_response_id FK 為 NO ACTION（multiple cascade paths 限制），
        // 刪回覆前先把事件參照設 null；其他 driver 有 DB SET NULL，重複更新無害。
        static::deleting(function (self $response): void {
            $response->events()->update(['survey_response_id' => null]);
        });
    }

    /**
     * @return HasMany<SurveyAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    /** @return array<string, mixed> */
    public function answerMapByFieldKey(): array
    {
        $this->loadMissing('answers.field');

        return $this->answers
            ->mapWithKeys(fn (SurveyAnswer $answer): array => [$answer->fieldKey() => $answer->getValue()])
            ->all();
    }

    /**
     * @return HasMany<SurveyResponseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SurveyResponseEvent::class);
    }

    /**
     * @return HasMany<SurveyResponseConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(SurveyResponseConsent::class);
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
