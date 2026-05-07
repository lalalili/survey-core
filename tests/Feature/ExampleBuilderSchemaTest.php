<?php

use Lalalili\SurveyCore\Actions\SyncSurveyBuilderSchemaToFieldsAction;
use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Tests\TestCase;

$exampleBuilderTestCase = class_exists(TestCase::class)
    ? TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($exampleBuilderTestCase === TestCase::class) {
    uses($exampleBuilderTestCase);
}

beforeEach(function () use ($exampleBuilderTestCase): void {
    if ($exampleBuilderTestCase === TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }
});

function abcVehicleOwnerSurveySchema(): array
{
    $path = dirname(__DIR__, 2).'/examples/abc-vehicle-owner-survey.builder.json';

    return json_decode(
        file_get_contents($path) ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('validates the ABC vehicle owner survey builder example', function (): void {
    $schema = abcVehicleOwnerSurveySchema();
    $validated = app(ValidateSurveyBuilderSchemaAction::class)->execute($schema);
    $encoded = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($validated['title'])->toBe('ABC 銷售滿意度與車輛使用體驗問卷')
        ->and($validated['pages'])->toHaveCount(8)
        ->and($validated['pages'][0]['id'])->toBe('page_welcome')
        ->and($validated['pages'][0]['kind'])->toBe('welcome')
        ->and($validated['pages'][0]['title'])->toBe('歡迎頁')
        ->and($validated['pages'][5]['id'])->toBe('page_vehicle_intro')
        ->and($validated['pages'][5]['title'])->toBe('感謝您的回饋')
        ->and($encoded)->toContain('0800-123-456')
        ->and($encoded)->not->toContain('0800-585-880')
        ->and($encoded)->not->toContain('FOXTRON');
});

it('keeps the sales and vehicle sections in the expected order', function (): void {
    $schema = app(ValidateSurveyBuilderSchemaAction::class)->execute(abcVehicleOwnerSurveySchema());

    $pageIds = array_column($schema['pages'], 'id');
    $salesQuestionNumbers = collect($schema['pages'])
        ->slice(2, 3)
        ->flatMap(fn (array $page): array => $page['elements'])
        ->pluck('label')
        ->map(function (string $label): ?int {
            preg_match('/^([1-9]|10)(?:-[1-9])?\\./', $label, $matches);

            return isset($matches[1]) ? (int) $matches[1] : null;
        })
        ->filter()
        ->unique()
        ->values();

    $vehicleScoreFields = collect($schema['pages'])
        ->slice(6, 2)
        ->flatMap(fn (array $page): array => $page['elements'])
        ->filter(fn (array $element): bool => str_ends_with((string) $element['field_key'], '_score'))
        ->values();

    expect($pageIds)->toBe([
        'page_welcome',
        'page_basic',
        'page_sales_core',
        'page_sales_test_drive',
        'page_sales_delivery',
        'page_vehicle_intro',
        'page_vehicle_experience_a',
        'page_vehicle_experience_b',
    ])
        ->and($salesQuestionNumbers)->toHaveCount(10)
        ->and($vehicleScoreFields)->toHaveCount(8)
        ->and(collect($schema['pages'][7]['elements'])->contains(fn (array $element): bool => $element['field_key'] === 'vehicle_other_feedback'))->toBeTrue();
});

it('uses description as the canonical content for content blocks', function (): void {
    $schema = app(ValidateSurveyBuilderSchemaAction::class)->execute(abcVehicleOwnerSurveySchema());

    $vehicleIntroPage = collect($schema['pages'])->firstWhere('id', 'page_vehicle_intro');
    $descriptionBlocks = collect($schema['pages'])
        ->flatMap(fn (array $page): array => $page['elements'])
        ->filter(fn (array $element): bool => $element['type'] === 'description_block')
        ->values();

    expect($vehicleIntroPage['elements'])->toHaveCount(1)
        ->and($vehicleIntroPage['elements'][0]['type'])->toBe('description_block')
        ->and($descriptionBlocks)->toHaveCount(1);

    $descriptionBlocks->each(function (array $element): void {
        expect($element['label'])->toBe('說明文字')
            ->and($element['description'])->toStartWith('<h2>')
            ->and($element['description'])->toContain('ABC 車輛使用體驗問卷')
            ->and($element['description'])->toEndWith('</p>');
    });
});

it('syncs jump logic and conditional issue notes from the example schema', function (): void {
    $schema = app(ValidateSurveyBuilderSchemaAction::class)->execute(abcVehicleOwnerSurveySchema());
    $survey = Survey::create(['title' => 'ABC Example', 'status' => SurveyStatus::Draft]);

    app(SyncSurveyBuilderSchemaToFieldsAction::class)->execute($survey, $schema);

    $testDriveField = $survey->fields()->where('field_key', 'sales_test_drive_experience')->firstOrFail();
    $issueNoteFields = $survey->fields()
        ->where('field_key', 'like', 'vehicle_%_issue_note')
        ->get();
    $npsFields = $survey->fields()->where('type', 'nps')->get();
    $contentBlockFields = $survey->fields()
        ->whereIn('type', ['section_title', 'description_block'])
        ->orderBy('sort_order')
        ->get();

    $noOption = collect($testDriveField->options_json)
        ->firstWhere('value', 'no');

    expect($noOption['action'])->toBe([
        'type' => 'go_to_page',
        'target_page_id' => 'page_sales_delivery',
    ])
        ->and($issueNoteFields)->toHaveCount(8)
        ->and($npsFields)->not->toBeEmpty()
        ->and($contentBlockFields)->toHaveCount(1)
        ->and($contentBlockFields->first()->field_key)->toBe('vehicle_intro_copy')
        ->and($contentBlockFields->first()->label)->toBe('說明文字')
        ->and($contentBlockFields->first()->description)->toContain('ABC 車輛使用體驗問卷');

    $issueNoteFields->each(function ($field): void {
        $expectedTriggerKey = str_replace('_issue_note', '_has_issue', $field->field_key);

        expect($field->is_required)->toBeFalse()
            ->and($field->show_if_field_key)->toBe($expectedTriggerKey)
            ->and($field->show_if_value)->toBe('yes');
    });

    $npsFields->each(function ($field): void {
        expect($field->settings_json)->toMatchArray([
            'low_label' => '1 分',
            'high_label' => '10 分',
            'color_bands' => false,
        ]);
    });
});
