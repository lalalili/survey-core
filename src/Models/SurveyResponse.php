<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
            'submitted_at'      => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(SurveyRecipient::class, 'survey_recipient_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(SurveyToken::class, 'survey_token_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SurveyTag::class, 'survey_response_tag');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('survey_files');
    }
}
