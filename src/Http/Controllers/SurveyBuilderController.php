<?php

namespace Lalalili\SurveyCore\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Lalalili\AudienceCore\Models\AudienceList;
use Lalalili\SurveyCore\Actions\BuildSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyTheme;

class SurveyBuilderController extends Controller
{
    public function show(Request $request, Survey $survey, BuildSurveyBuilderSchemaAction $buildSchema): JsonResponse
    {
        Gate::authorize('update', $survey);

        return response()->json([
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'status' => $survey->status->value,
                'version' => $survey->version,
                'published_at' => $survey->published_at?->toIso8601String(),
            ],
            'schema' => $buildSchema->execute($survey),
            'themes' => SurveyTheme::query()
                ->where('is_system', true)
                ->orderBy('name')
                ->get(['id', 'name', 'tokens_json'])
                ->map(fn (SurveyTheme $theme): array => [
                    'id' => $theme->id,
                    'name' => $theme->name,
                    'tokens' => $theme->tokens_json ?? [],
                ])
                ->all(),
            'audience_lists' => AudienceList::query()
                ->orderBy('name')
                ->get(['id', 'name', 'columns_json'])
                ->map(fn (AudienceList $audienceList): array => [
                    'id' => $audienceList->id,
                    'name' => $audienceList->name,
                    'columns' => $audienceList->columns_json ?? [],
                ])
                ->all(),
            'capabilities' => [
                'can_manage_advanced_fields' => $this->canManageAdvancedFields($request),
                'question_types' => collect(SurveyFieldType::cases())
                    ->reject(fn (SurveyFieldType $type): bool => in_array($type, [
                        SurveyFieldType::Hidden,
                        SurveyFieldType::Email,
                        SurveyFieldType::Phone,
                        SurveyFieldType::Address,
                    ], true))
                    ->map(fn (SurveyFieldType $type): string => $type->value)
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function canManageAdvancedFields(Request $request): bool
    {
        $user = $request->user();

        if ((bool) data_get($user, 'is_super_admin') || (bool) data_get($user, 'isSuperAdmin')) {
            return true;
        }

        $checkerClass = 'Lalalili\\SubscriptionCore\\Support\\SubscriptionFeatureChecker';

        if (! class_exists($checkerClass)) {
            return false;
        }

        $owner = data_get($user, (string) config('survey-filament.subscription_owner_path', 'merchant'));

        if (! $owner instanceof Model) {
            return false;
        }

        return app($checkerClass)->hasFeature(
            $owner,
            (string) config('survey-filament.advanced_fields_feature_key', 'survey.advanced_fields'),
        );
    }

    public function update(Request $request, Survey $survey, SaveSurveyDraftSchemaAction $saveSchema): JsonResponse
    {
        Gate::authorize('update', $survey);

        $data = $request->validate([
            'schema' => ['required', 'array'],
        ]);

        try {
            $survey = $saveSchema->execute($survey, $data['schema']);
        } catch (SurveyValidationException $exception) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $exception->getErrors(),
            ], 422);
        }

        return response()->json([
            'saved_at' => now()->toIso8601String(),
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'status' => $survey->status->value,
                'version' => $survey->version,
            ],
            'schema' => $survey->draft_schema,
        ]);
    }

    public function uploadImage(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $disk = (string) config('filament.default_filesystem_disk', 'public');
        $path = $request->file('file')->store(
            'survey-builder/'.$survey->getKey().'/'.now()->format('Y/m'),
            $disk,
        );

        if ($path === false || $path === '') {
            return response()->json(['message' => '圖片上傳失敗，請再試一次。'], 500);
        }

        return response()->json([
            'url' => Storage::disk($disk)->url($path),
        ]);
    }

    public function publish(Survey $survey, PublishSurveyAction $publishSurvey): JsonResponse
    {
        Gate::authorize('update', $survey);

        try {
            $survey = $publishSurvey->execute($survey);
        } catch (SurveyNotAvailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (SurveyValidationException $exception) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $exception->getErrors(),
            ], 422);
        }

        return response()->json([
            'published_at' => $survey->published_at?->toIso8601String(),
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'status' => $survey->status->value,
                'version' => $survey->version,
            ],
            'schema' => $survey->published_schema,
        ]);
    }
}
