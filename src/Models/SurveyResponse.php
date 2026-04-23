<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;

class SurveyResponse extends Model
{
    protected $fillable = [
        'survey_id',
        'survey_recipient_id',
        'survey_token_id',
        'submitted_at',
        'ip',
        'user_agent',
        'completion_status',
    ];

    protected function casts(): array
    {
        return [
            'completion_status' => SurveyResponseCompletionStatus::class,
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
}
