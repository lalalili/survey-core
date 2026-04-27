<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SurveyTag extends Model
{
    protected $fillable = [
        'survey_id',
        'name',
        'color',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function responses(): BelongsToMany
    {
        return $this->belongsToMany(SurveyResponse::class, 'survey_response_tag');
    }
}
