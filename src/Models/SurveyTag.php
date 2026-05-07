<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $survey_id
 * @property string $name
 * @property string|null $color
 * @property-read Survey $survey
 * @property-read Collection<int, SurveyResponse> $responses
 */
class SurveyTag extends Model
{
    protected $fillable = [
        'survey_id',
        'name',
        'color',
    ];

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return BelongsToMany<SurveyResponse, $this>
     */
    public function responses(): BelongsToMany
    {
        return $this->belongsToMany(SurveyResponse::class, 'survey_response_tag');
    }
}
