<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveySchemaVersion;
use Lalalili\SurveyCore\Services\DefaultPersonalizationResolver;
use Lalalili\SurveyCore\Support\JumpLogicResolver;

beforeEach(function (): void {
    $this->app->bind(PersonalizationResolver::class, DefaultPersonalizationResolver::class);
    Route::post('/survey-test/{publicKey}/upload', [PublicSurveyController::class, 'upload']);
});

function phase2Survey(): Survey
{
    return Survey::create(['title' => 'Phase 2', 'status' => SurveyStatus::Published]);
}

function phase2Field(Survey $survey, SurveyFieldType $type, array $attributes = []): SurveyField
{
    return SurveyField::create(array_merge([
        'survey_id' => $survey->id,
        'type' => $type,
        'label' => $attributes['field_key'] ?? $type->value,
        'field_key' => $attributes['field_key'] ?? $type->value,
        'is_required' => $attributes['is_required'] ?? true,
        'sort_order' => $attributes['sort_order'] ?? 1,
    ], $attributes));
}

it('validates number and nps ranges', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::Number, [
        'field_key' => 'amount',
        'settings_json' => ['min' => 0, 'max' => 10],
    ]);
    phase2Field($survey, SurveyFieldType::Nps, ['field_key' => 'nps', 'sort_order' => 2]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['amount' => 5, 'nps' => 10]),
    );

    expect($response->answers)->toHaveCount(2);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['amount' => 11, 'nps' => 11]),
    );
})->throws(SurveyValidationException::class);

it('validates rating answers against configured count', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::Rating, [
        'field_key' => 'satisfaction',
        'settings_json' => ['count' => 10, 'shape' => 'star', 'show_numbers' => true],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['satisfaction' => 10]),
    );

    expect($response->answers)->toHaveCount(1);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['satisfaction' => 11]),
    );
})->throws(SurveyValidationException::class);

it('validates time and linear scale answers', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::Time, ['field_key' => 'arrival_time']);
    phase2Field($survey, SurveyFieldType::LinearScale, [
        'field_key' => 'effort',
        'sort_order' => 2,
        'settings_json' => ['min' => 1, 'max' => 5, 'step' => 1],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['arrival_time' => '09:30', 'effort' => 4]),
    );

    expect($response->answers)->toHaveCount(2);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['arrival_time' => '9:30', 'effort' => 6]),
    );
})->throws(SurveyValidationException::class);

it('validates constant sum answers against options and configured total', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::ConstantSum, [
        'field_key' => 'budget',
        'options_json' => [
            ['id' => 'a', 'label' => 'A', 'value' => 'a'],
            ['id' => 'b', 'label' => 'B', 'value' => 'b'],
        ],
        'settings_json' => ['total' => 100],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['budget' => ['a' => 40, 'b' => 60]]),
    );

    expect($response->answers->first()->getValue())->toBe(['a' => 40, 'b' => 60]);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['budget' => ['a' => 40, 'b' => 50]]),
    );
})->throws(SurveyValidationException::class);

it('renders constant sum public summary state', function (): void {
    $survey = phase2Survey();
    $survey->update(['allow_anonymous' => true]);

    phase2Field($survey, SurveyFieldType::ConstantSum, [
        'field_key' => 'budget',
        'label' => '預算分配',
        'options_json' => [
            ['id' => 'a', 'label' => '廣告', 'value' => 'ads'],
            ['id' => 'b', 'label' => '活動', 'value' => 'events'],
        ],
        'settings_json' => ['total' => 100, 'unit' => '%'],
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('data-constant-sum-total="100"', false)
        ->assertSee('data-constant-sum-summary', false)
        ->assertSee('data-constant-sum-current', false)
        ->assertSee('data-constant-sum-status', false)
        ->assertSee('目前合計')
        ->assertSee('目標 100')
        ->assertSee('剩餘 100');
});

it('validates matrix answers per row and persists structured json', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::MatrixSingle, [
        'field_key' => 'matrix',
        'settings_json' => [
            'matrix_rows' => [['id' => 'quality', 'label' => '品質'], ['id' => 'service', 'label' => '服務']],
            'matrix_cols' => [['id' => 'good', 'label' => '好'], ['id' => 'bad', 'label' => '差']],
        ],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['matrix' => ['quality' => 'good', 'service' => 'bad']]),
    );

    expect($response->answers->first()->getValue())->toBe(['quality' => 'good', 'service' => 'bad']);
});

it('rejects incomplete required ranking and accepts complete ranking', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::Ranking, [
        'field_key' => 'rank',
        'options_json' => [
            ['id' => 'a', 'label' => 'A', 'value' => 'a'],
            ['id' => 'b', 'label' => 'B', 'value' => 'b'],
        ],
    ]);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload(['rank' => ['a']]),
    );
})->throws(SurveyValidationException::class);

it('renders ranking fields as a sortable public list', function (): void {
    $survey = phase2Survey();
    $survey->update(['allow_anonymous' => true]);

    phase2Field($survey, SurveyFieldType::Ranking, [
        'field_key' => 'rank',
        'options_json' => [
            ['id' => 'a', 'label' => 'A', 'value' => 'a'],
            ['id' => 'b', 'label' => 'B', 'value' => 'b'],
        ],
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('data-ranking-list="rank"', false)
        ->assertSee('data-ranking-item', false)
        ->assertSee('data-ranking-move="up"', false)
        ->assertSee('data-ranking-move="down"', false)
        ->assertDontSee('data-ranking-field', false);
});

it('renders local storage draft persistence on the public form', function (): void {
    $survey = phase2Survey();
    $survey->update(['allow_anonymous' => true]);
    phase2Field($survey, SurveyFieldType::ShortText, ['field_key' => 'name']);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('DRAFT_STORAGE_KEY', false)
        ->assertSee('window.localStorage.setItem', false)
        ->assertSee('window.localStorage.removeItem', false)
        ->assertSee('lalalili-survey-draft', false);
});

it('renders file upload limits and drag drop affordance on the public form', function (): void {
    $survey = phase2Survey();
    $survey->update(['allow_anonymous' => true]);

    phase2Field($survey, SurveyFieldType::FileUpload, [
        'field_key' => 'attachment',
        'settings_json' => [
            'max_size_mb' => 10,
            'allowed_mimes' => ['pdf', 'jpg', 'png'],
        ],
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('選擇檔案或將檔案拖曳至此')
        ->assertSee('10 MB以下')
        ->assertSee('檔案格式：文件、圖片')
        ->assertSee('accept=".pdf,.jpg,.png"', false)
        ->assertSee('data-file-upload-zone="attachment"', false);
});

it('validates signature and address structured answers', function (): void {
    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::Signature, ['field_key' => 'signature']);
    phase2Field($survey, SurveyFieldType::Address, [
        'field_key' => 'address',
        'sort_order' => 2,
        'settings_json' => ['fields_enabled' => ['country', 'city'], 'country_locked' => '台灣'],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->load('fields'),
        new SubmissionPayload([
            'signature' => ['data_url' => 'data:image/png;base64,'.str_repeat('a', 220)],
            'address' => ['country' => '台灣', 'city' => '台北市'],
        ]),
    );

    expect($response->answers)->toHaveCount(2);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload([
            'signature' => ['data_url' => ''],
            'address' => ['country' => '日本', 'city' => '東京'],
        ]),
    );
})->throws(SurveyValidationException::class);

it('evaluates condition groups and page-level jump rules', function (): void {
    $survey = phase2Survey();
    $pageOne = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_1', 'title' => 'One', 'kind' => SurveyPageKind::Question, 'sort_order' => 1]);
    $pageTwo = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_2', 'title' => 'Two', 'kind' => SurveyPageKind::Question, 'sort_order' => 2]);
    $pageThree = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_3', 'title' => 'Three', 'kind' => SurveyPageKind::Question, 'sort_order' => 3]);

    $pageOne->update(['settings_json' => [
        'jump_rules' => [[
            'condition' => ['logic' => 'and', 'conditions' => [['field_key' => 'dept', 'op' => 'equals', 'value' => 'sales']]],
            'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_3'],
        ]],
    ]]);

    phase2Field($survey, SurveyFieldType::ShortText, ['field_key' => 'dept', 'survey_page_id' => $pageOne->id]);
    $conditional = phase2Field($survey, SurveyFieldType::ShortText, [
        'field_key' => 'conditional',
        'survey_page_id' => $pageTwo->id,
        'settings_json' => ['show_if' => ['logic' => 'or', 'conditions' => [
            ['field_key' => 'dept', 'op' => 'equals', 'value' => 'sales'],
            ['field_key' => 'dept', 'op' => 'contains', 'value' => 'ops'],
        ]]],
    ]);

    expect($conditional->isConditionallyVisible(['dept' => 'sales']))->toBeTrue()
        ->and($conditional->isConditionallyVisible(['dept' => 'finance']))->toBeFalse()
        ->and(JumpLogicResolver::resolveVisitedPages($survey->load('pages', 'fields'), ['dept' => 'sales']))->toBe([$pageOne->id, $pageThree->id]);
});

it('uploads files through media library and stores returned media metadata as an answer', function (): void {
    if (! Schema::hasTable('media')) {
        Schema::create('media', function ($table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::FileUpload, [
        'field_key' => 'resume',
        'settings_json' => ['max_size_mb' => 1, 'allowed_mimes' => ['pdf']],
    ]);
    $schemaVersion = SurveySchemaVersion::create([
        'survey_id' => $survey->id,
        'version' => 1,
        'schema_json' => ['pages' => []],
        'source' => 'test',
        'published_at' => now(),
    ]);
    $survey->update(['published_schema_version_id' => $schemaVersion->id]);

    $upload = $this->post('/survey-test/'.$survey->public_key.'/upload', [
        'field_key' => 'resume',
        'schema_version_id' => $schemaVersion->id,
        'file' => UploadedFile::fake()->create('resume.pdf', 12, 'application/pdf'),
    ]);

    $upload->assertCreated();
    expect($upload->json('upload_token'))->toBeString()->not->toBe('');

    // 上傳時建立的 Partial 暫存草稿
    $draft = SurveyResponse::where('completion_status', SurveyResponseCompletionStatus::Partial->value)->sole();
    expect($draft->schema_version_id)->toBe($schemaVersion->id);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['resume' => $upload->json()]),
    );

    expect($response->answers->first()->getValue()['media_id'])->toBe($upload->json('media_id'))
        ->and(SurveyResponse::find($response->id)?->getMedia('survey_files'))->toHaveCount(1)
        // 媒體搬到正式回覆後，已搬空的暫存草稿應被刪除
        ->and(SurveyResponse::find($draft->id))->toBeNull()
        ->and(SurveyResponse::where('completion_status', SurveyResponseCompletionStatus::Partial->value)->count())->toBe(0);
});

it('rejects file upload answers when the upload token is missing', function (): void {
    if (! Schema::hasTable('media')) {
        Schema::create('media', function ($table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    $survey = phase2Survey();
    phase2Field($survey, SurveyFieldType::FileUpload, [
        'field_key' => 'resume',
        'settings_json' => ['max_size_mb' => 1, 'allowed_mimes' => ['pdf']],
    ]);

    $upload = $this->post('/survey-test/'.$survey->public_key.'/upload', [
        'field_key' => 'resume',
        'file' => UploadedFile::fake()->create('resume.pdf', 12, 'application/pdf'),
    ]);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload([
            'resume' => [
                'media_id' => $upload->json('media_id'),
                'filename' => $upload->json('filename'),
                'size' => $upload->json('size'),
            ],
        ]),
    );
})->throws(SurveyValidationException::class);
