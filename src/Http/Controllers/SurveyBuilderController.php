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
use Lalalili\SurveyCore\Actions\CreateCascadeSelectTemplateAction;
use Lalalili\SurveyCore\Actions\ParseCascadeSelectImportAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\RecordSurveyBuilderActivityAction;
use Lalalili\SurveyCore\Actions\RestoreSurveyPublishedSchemaAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyTheme;
use Lalalili\SurveyCore\Support\ImageUploadSanitizer;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
                'has_unpublished_changes' => $survey->published_schema === null
                    || $survey->draft_schema !== $survey->published_schema,
                'google_drive' => [
                    'connected' => $survey->google_drive_account_id !== null,
                    'email' => $survey->googleDriveAccount?->email,
                    'configured' => $this->googleDriveIsConfigured(),
                ],
            ],
            'schema' => $buildSchema->execute($survey),
            'field_impacts' => $this->fieldImpacts($survey),
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
                ->get(['id', 'name', 'schema_profile', 'columns_json'])
                ->map(fn (AudienceList $audienceList): array => [
                    'id' => $audienceList->id,
                    'name' => $audienceList->name,
                    'schema_profile' => $audienceList->schema_profile,
                    'columns' => $audienceList->columns_json ?? [],
                ])
                ->all(),
            'capabilities' => [
                'can_manage_advanced_fields' => $this->canManageAdvancedFields($request),
                'is_super_admin' => $this->isSuperAdmin($request),
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

    /**
     * @return array<string, array{element_id: string, field_key: string, answer_count: int, response_count: int, locked_properties: list<string>}>
     */
    private function fieldImpacts(Survey $survey): array
    {
        if (! is_array($survey->published_schema)) {
            return [];
        }

        $publishedPages = is_array($survey->published_schema['pages'] ?? null)
            ? $survey->published_schema['pages']
            : [];
        $elements = collect($publishedPages)
            ->flatMap(fn (mixed $page): array => is_array($page) && is_array($page['elements'] ?? null) ? $page['elements'] : [])
            ->filter(fn (mixed $element): bool => is_array($element) && filled($element['id'] ?? null) && filled($element['field_key'] ?? null));
        $fieldKeys = $elements->pluck('field_key')->map(fn (mixed $key): string => (string) $key)->all();
        $fields = $survey->fields()
            ->whereIn('field_key', $fieldKeys)
            ->withCount('answers')
            ->get()
            ->keyBy('field_key');
        $responseCounts = SurveyAnswer::query()
            ->whereIn('survey_field_id', $fields->pluck('id'))
            ->selectRaw('survey_field_id, count(distinct survey_response_id) as aggregate')
            ->groupBy('survey_field_id')
            ->pluck('aggregate', 'survey_field_id');

        return $elements->mapWithKeys(function (array $element) use ($fields, $responseCounts): array {
            $elementId = (string) $element['id'];
            $fieldKey = (string) $element['field_key'];
            $field = $fields->get($fieldKey);
            $answerCount = $field instanceof SurveyField ? (int) $field->answers_count : 0;

            return [$elementId => [
                'element_id' => $elementId,
                'field_key' => $fieldKey,
                'answer_count' => $answerCount,
                'response_count' => $field instanceof SurveyField ? (int) ($responseCounts[$field->id] ?? 0) : 0,
                'locked_properties' => $answerCount > 0 ? ['field_key', 'type', 'used_option_values'] : [],
            ]];
        })->all();
    }

    private function googleDriveIsConfigured(): bool
    {
        return (bool) config('survey-core.google_drive.enabled')
            && filled(config('survey-core.google_drive.client_id'))
            && filled(config('survey-core.google_drive.client_secret'));
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();

        if ((bool) data_get($user, 'is_super_admin') || (bool) data_get($user, 'isSuperAdmin')) {
            return true;
        }

        if (! is_object($user) || ! method_exists($user, 'hasRole')) {
            return false;
        }

        return (bool) $user->hasRole('super_admin');
    }

    private function canManageAdvancedFields(Request $request): bool
    {
        if ($this->isSuperAdmin($request)) {
            return true;
        }

        $user = $request->user();
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

    public function update(Request $request, Survey $survey, SaveSurveyDraftSchemaAction $saveSchema, RecordSurveyBuilderActivityAction $recordActivity): JsonResponse
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

        $recordActivity->recordAutosave($survey, $request->user());

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

    public function uploadImage(Request $request, Survey $survey, ImageUploadSanitizer $imageUploadSanitizer): JsonResponse
    {
        Gate::authorize('update', $survey);

        $acceptedMimes = array_values((array) config('survey-core.builder_images.accepted_mimes', ['jpg', 'jpeg', 'png', 'webp', 'gif']));
        $maxSize = (int) config('survey-core.builder_images.max_size', 5120);

        $request->validate([
            'file' => ['required', 'image', 'mimes:'.implode(',', $acceptedMimes), 'max:'.$maxSize],
        ]);

        $disk = (string) config('survey-core.builder_images.disk', 'public');
        $path = $imageUploadSanitizer->store(
            $request->file('file'),
            'survey-builder/'.$survey->getKey().'/'.now()->format('Y/m'),
            $disk,
            'public',
        );

        if ($path === false || $path === '') {
            return response()->json(['message' => '圖片上傳失敗，請再試一次。'], 500);
        }

        return response()->json([
            'url' => url(Storage::disk($disk)->url($path)),
        ]);
    }

    public function downloadCascadeTemplate(Survey $survey, CreateCascadeSelectTemplateAction $createTemplate): BinaryFileResponse
    {
        Gate::authorize('update', $survey);

        $path = tempnam(sys_get_temp_dir(), 'cascade-select-template-');

        if ($path === false) {
            abort(500, 'Unable to create template file.');
        }

        $xlsxPath = $path.'.xlsx';
        rename($path, $xlsxPath);

        $createTemplate->writeToPath($xlsxPath);

        return response()
            ->download($xlsxPath, 'cascade-select-template.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function importCascadeData(Request $request, Survey $survey, ParseCascadeSelectImportAction $parseImport): JsonResponse
    {
        Gate::authorize('update', $survey);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        ]);

        $path = $request->file('file')?->getRealPath();

        if (! is_string($path) || $path === '') {
            return response()->json([
                'message' => '檔案上傳失敗，請重新選擇檔案。',
                'errors' => [
                    'file' => ['檔案上傳失敗，請重新選擇檔案。'],
                ],
            ], 422);
        }

        try {
            return response()->json($parseImport->execute($path));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'file' => [$exception->getMessage()],
                ],
            ], 422);
        }
    }

    public function publish(Request $request, Survey $survey, PublishSurveyAction $publishSurvey, RecordSurveyBuilderActivityAction $recordActivity): JsonResponse
    {
        Gate::authorize('update', $survey);

        $previousVersion = $survey->version;
        $previousPublishedAt = $survey->published_at?->toIso8601String();

        try {
            $survey = $publishSurvey->execute($survey);
        } catch (SurveyNotAvailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'publish' => [$exception->getMessage()],
                ],
            ], $exception->getStatusCode());
        } catch (SurveyValidationException $exception) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $exception->getErrors(),
            ], 422);
        }

        if ($survey->version !== $previousVersion || $survey->published_at?->toIso8601String() !== $previousPublishedAt) {
            $recordActivity->recordPublished($survey, $request->user());
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

    public function activities(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $activities = Activity::query()
            ->with('causer')
            ->forSubject($survey)
            ->where(function ($query): void {
                $query
                    ->where('log_name', 'survey_builder')
                    ->orWhere('event', 'created');
            })
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Activity $activity): array => $this->formatActivity($activity))
            ->all();

        return response()->json([
            'items' => $activities,
            'can_restore_published' => is_array($survey->published_schema),
            'published_at' => $survey->published_at?->toIso8601String(),
            'current_version' => $survey->version,
        ]);
    }

    public function restorePublished(Request $request, Survey $survey, RestoreSurveyPublishedSchemaAction $restoreSurvey, RecordSurveyBuilderActivityAction $recordActivity): JsonResponse
    {
        Gate::authorize('update', $survey);

        try {
            $survey = $restoreSurvey->execute($survey);
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

        $recordActivity->recordRestoredPublished($survey, $request->user());

        return response()->json([
            'restored_at' => now()->toIso8601String(),
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'status' => $survey->status->value,
                'version' => $survey->version,
                'published_at' => $survey->published_at?->toIso8601String(),
            ],
            'schema' => $survey->draft_schema,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatActivity(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'label' => match ($activity->event) {
                'created' => '建立問卷',
                'autosaved' => '自動儲存',
                'published' => '發布問卷',
                'restored_published' => '回復版本',
                default => $activity->description,
            },
            'description' => $activity->description,
            'causer_name' => $activity->causer?->getAttribute('name'),
            'created_at' => $activity->created_at?->toIso8601String(),
            'version' => $properties['version'] ?? null,
            'autosave_count' => $properties['autosave_count'] ?? null,
        ];
    }
}
