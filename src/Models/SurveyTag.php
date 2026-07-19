<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Lalalili\AudienceCore\Concerns\LogsModelActivity;

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
    use LogsModelActivity;

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

    protected static function booted(): void
    {
        // sqlsrv 上 pivot.survey_tag_id FK 為 NO ACTION（multiple cascade paths 限制），
        // 刪標籤前先清 pivot；其他 driver 有 DB cascade，重複刪除無害。
        static::deleting(function (self $tag): void {
            $tag->responses()->detach();
        });
    }

    /**
     * @return BelongsToMany<SurveyResponse, $this>
     */
    public function responses(): BelongsToMany
    {
        return $this->belongsToMany(SurveyResponse::class, 'survey_response_tag');
    }
}
