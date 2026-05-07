<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $audience_list_id
 * @property array<string, mixed>|null $data_json
 * @property string|null $status
 * @property-read AudienceList $audienceList
 * @property-read Collection<int, SurveyRecipient> $surveyRecipients
 */
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

    /**
     * @return BelongsTo<AudienceList, $this>
     */
    public function audienceList(): BelongsTo
    {
        return $this->belongsTo(AudienceList::class);
    }

    /**
     * @return HasMany<SurveyRecipient, $this>
     */
    public function surveyRecipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }

    public function getDataValue(string $key): mixed
    {
        return ($this->data_json ?? [])[$key] ?? null;
    }
}
