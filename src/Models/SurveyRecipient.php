<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;

/**
 * @property int $id
 * @property int $survey_id
 * @property int|null $audience_list_row_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $external_id
 * @property array<string, mixed>|null $payload_json
 * @property bool $is_test
 * @property Carbon|null $invitation_opened_at
 * @property SurveyRecipientStatus $status
 * @property-read Survey $survey
 * @property-read AudienceListRow|null $audienceListRow
 * @property-read Collection<int, SurveyToken> $tokens
 * @property-read Collection<int, SurveyResponse> $responses
 */
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
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyRecipientStatus::class,
            'payload_json' => 'array',
            'is_test' => 'boolean',
            'invitation_opened_at' => 'datetime',
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
     * @return BelongsTo<AudienceListRow, $this>
     */
    public function audienceListRow(): BelongsTo
    {
        return $this->belongsTo(AudienceListRow::class);
    }

    /**
     * @return HasMany<SurveyToken, $this>
     */
    protected static function booted(): void
    {
        // sqlsrv 上 tokens.survey_recipient_id 與 responses.survey_recipient_id FK 為
        // NO ACTION（multiple cascade paths 限制），刪收件人前先解除參照並清 tokens；
        // 其他 driver 有 DB cascade / SET NULL，重複操作無害。
        static::deleting(function (self $recipient): void {
            SurveyResponse::query()
                ->whereIn('survey_token_id', $recipient->tokens()->select('id'))
                ->update(['survey_token_id' => null]);
            $recipient->tokens()->delete();
            $recipient->responses()->update(['survey_recipient_id' => null]);
        });
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SurveyToken::class);
    }

    /**
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function getPayloadValue(string $key): mixed
    {
        return ($this->payload_json ?? [])[$key] ?? null;
    }
}
