<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyPageKind;

/**
 * @property int $id
 * @property int $survey_id
 * @property string $page_key
 * @property string|null $title
 * @property SurveyPageKind $kind
 * @property int $sort_order
 * @property array<string, mixed>|null $settings_json
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyField> $fields
 */
class SurveyPage extends Model
{
    protected $fillable = [
        'survey_id',
        'page_key',
        'title',
        'kind',
        'sort_order',
        'settings_json',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SurveyPageKind::class,
            'sort_order' => 'integer',
            'settings_json' => 'array',
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
     * @return HasMany<SurveyField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class, 'survey_page_id')->orderBy('sort_order');
    }
}
