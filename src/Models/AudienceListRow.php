<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AudienceListRow extends Model
{
    protected $fillable = [
        'audience_list_id',
        'data_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data_json' => 'array',
        ];
    }

    public function audienceList(): BelongsTo
    {
        return $this->belongsTo(AudienceList::class);
    }

    public function surveyRecipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }

    public function getDataValue(string $key): mixed
    {
        return ($this->data_json ?? [])[$key] ?? null;
    }
}
