<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lalalili\SurveyCore\Enums\SurveyPageKind;

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
            'kind'          => SurveyPageKind::class,
            'sort_order'    => 'integer',
            'settings_json' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class, 'survey_page_id')->orderBy('sort_order');
    }
}
