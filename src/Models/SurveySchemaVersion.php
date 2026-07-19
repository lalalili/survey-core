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
 * @property int $version
 * @property array<string, mixed> $schema_json
 * @property string $source
 * @property Carbon|null $published_at
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyFieldVersion> $fieldVersions
 * @property-read Collection<int, SurveyResponse> $responses
 */
class SurveySchemaVersion extends Model
{
    protected $fillable = [
        'survey_id',
        'version',
        'schema_json',
        'source',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_json' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Survey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** @return HasMany<SurveyFieldVersion, $this> */
    public function fieldVersions(): HasMany
    {
        return $this->hasMany(SurveyFieldVersion::class, 'schema_version_id')->orderBy('sort_order');
    }

    /** @return HasMany<SurveyResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'schema_version_id');
    }
}
