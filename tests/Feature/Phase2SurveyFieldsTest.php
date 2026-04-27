<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Services\DefaultPersonalizationResolver;
use Lalalili\SurveyCore\Support\JumpLogicResolver;

$phase2SurveyFieldsTestCase = class_exists(Tests\TestCase::class)
    ? Tests\TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($phase2SurveyFieldsTestCase === Tests\TestCase::class) {
    uses($phase2SurveyFieldsTestCase);
}

beforeEach(function () use ($phase2SurveyFieldsTestCase): void {
    if ($phase2SurveyFieldsTestCase === Tests\TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }

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

    $upload = $this->post('/survey-test/'.$survey->public_key.'/upload', [
        'field_key' => 'resume',
        'file' => UploadedFile::fake()->create('resume.pdf', 12, 'application/pdf'),
    ]);

    $upload->assertCreated();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey->refresh()->load('fields'),
        new SubmissionPayload(['resume' => $upload->json()]),
    );

    expect($response->answers->first()->getValue()['media_id'])->toBe($upload->json('media_id'))
        ->and(SurveyResponse::find($response->id)?->getMedia('survey_files'))->toHaveCount(1);
});
