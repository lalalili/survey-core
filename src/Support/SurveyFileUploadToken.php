<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Support\Facades\Crypt;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SurveyFileUploadToken
{
    public function issue(Media $media, SurveyResponse $draftResponse, SurveyField $field): string
    {
        return Crypt::encryptString(json_encode([
            'media_id' => $media->id,
            'draft_response_id' => $draftResponse->id,
            'survey_id' => $draftResponse->survey_id,
            'field_key' => $field->field_key,
            'expires_at' => now()->addMinutes((int) config('survey-core.uploads.token_ttl_minutes', 30))->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function resolve(array $value, Survey $survey, SurveyField $field): ?Media
    {
        $mediaId = $value['media_id'] ?? null;
        $token = $value['upload_token'] ?? null;

        if (! is_scalar($mediaId) || ! is_string($token) || $token === '') {
            return null;
        }

        $payload = $this->decode($token);

        if ($payload === null) {
            return null;
        }

        if (
            (int) ($payload['media_id'] ?? 0) !== (int) $mediaId
            || (int) ($payload['survey_id'] ?? 0) !== (int) $survey->id
            || (string) ($payload['field_key'] ?? '') !== $field->field_key
            || (int) ($payload['expires_at'] ?? 0) < now()->timestamp
        ) {
            return null;
        }

        $draftResponse = SurveyResponse::query()->find((int) ($payload['draft_response_id'] ?? 0));

        if (
            ! $draftResponse instanceof SurveyResponse
            || (int) $draftResponse->survey_id !== (int) $survey->id
            || $draftResponse->completion_status !== SurveyResponseCompletionStatus::Partial
            || $draftResponse->submitted_at !== null
        ) {
            return null;
        }

        $media = Media::query()
            ->whereKey((int) $mediaId)
            ->where('collection_name', 'survey_files')
            ->first();

        if (! $media instanceof Media) {
            return null;
        }

        if (
            $media->model_type !== $draftResponse->getMorphClass()
            || (int) $media->model_id !== (int) $draftResponse->getKey()
            || (string) $media->getCustomProperty('survey_field_key') !== $field->field_key
        ) {
            return null;
        }

        return $media;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
