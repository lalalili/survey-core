<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;

class SurveyRecipient extends Model
{
    protected $fillable = [
        'survey_id',
        'audience_list_row_id',
        'name',
        'email',
        'external_id',
        'payload_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status'       => SurveyRecipientStatus::class,
            'payload_json' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function audienceListRow(): BelongsTo
    {
        return $this->belongsTo(AudienceListRow::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SurveyToken::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function getPayloadValue(string $key): mixed
    {
        return ($this->payload_json ?? [])[$key] ?? null;
    }
}
