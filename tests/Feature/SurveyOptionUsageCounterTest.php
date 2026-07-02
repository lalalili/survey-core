<?php

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Support\SurveyOptionUsageCounter;

function makeUsageField(SurveyFieldType $type, array $options): SurveyField
{
    $survey = Survey::create(['title' => 'Usage', 'status' => SurveyStatus::Published]);

    return SurveyField::create([
        'survey_id' => $survey->id,
        'type' => $type,
        'label' => 'Session',
        'field_key' => 'session',
        'is_required' => false,
        'options_json' => $options,
        'sort_order' => 1,
    ]);
}

function addUsageAnswer(SurveyField $field, ?string $text = null, ?array $json = null): void
{
    $response = SurveyResponse::create([
        'survey_id' => $field->survey_id,
        'submitted_at' => now(),
        'completion_status' => 'complete',
    ]);

    SurveyAnswer::create([
        'survey_response_id' => $response->id,
        'survey_field_id' => $field->id,
        'answer_text' => $text,
        'answer_json' => $json,
    ]);
}

it('counts single-value answers per option', function () {
    $field = makeUsageField(SurveyFieldType::SingleChoice, [
        ['value' => 'morning', 'label' => '早場', 'capacity' => 3],
        ['value' => 'night', 'label' => '晚場', 'capacity' => 3],
    ]);

    addUsageAnswer($field, text: 'morning');
    addUsageAnswer($field, text: 'morning');
    addUsageAnswer($field, text: 'night');

    expect(SurveyOptionUsageCounter::count($field, ['morning', 'night']))
        ->toBe(['morning' => 2, 'night' => 1]);
});

it('counts array answers stored in answer_json', function () {
    $field = makeUsageField(SurveyFieldType::MultipleChoice, [
        ['value' => 'a', 'label' => 'A', 'capacity' => 2],
        ['value' => 'b', 'label' => 'B', 'capacity' => 2],
    ]);

    addUsageAnswer($field, json: ['a', 'b']);
    addUsageAnswer($field, json: ['a']);

    expect(SurveyOptionUsageCounter::count($field, ['a', 'b']))
        ->toBe(['a' => 2, 'b' => 1]);
});

it('does not double count a row matching both text and json for the same value', function () {
    $field = makeUsageField(SurveyFieldType::MultipleChoice, [
        ['value' => 'a', 'label' => 'A', 'capacity' => 2],
    ]);

    addUsageAnswer($field, text: 'a', json: ['a']);

    expect(SurveyOptionUsageCounter::count($field, ['a']))
        ->toBe(['a' => 1]);
});

it('ignores answers from other fields', function () {
    $field = makeUsageField(SurveyFieldType::SingleChoice, [
        ['value' => 'morning', 'label' => '早場', 'capacity' => 3],
    ]);
    $otherField = makeUsageField(SurveyFieldType::SingleChoice, [
        ['value' => 'morning', 'label' => '早場', 'capacity' => 3],
    ]);

    addUsageAnswer($otherField, text: 'morning');

    expect(SurveyOptionUsageCounter::count($field, ['morning']))
        ->toBe(['morning' => 0]);
});

it('runs at most two queries regardless of option count', function () {
    $options = collect(range(1, 20))
        ->map(fn (int $i): array => ['value' => "opt_{$i}", 'label' => "選項 {$i}", 'capacity' => 5])
        ->all();
    $field = makeUsageField(SurveyFieldType::SingleChoice, $options);

    addUsageAnswer($field, text: 'opt_1');
    addUsageAnswer($field, json: ['opt_2', 'opt_3']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $counts = SurveyOptionUsageCounter::count($field, array_map(fn (array $o): string => $o['value'], $options));

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2)
        ->and($counts['opt_1'])->toBe(1)
        ->and($counts['opt_2'])->toBe(1)
        ->and($counts['opt_3'])->toBe(1)
        ->and($counts['opt_4'])->toBe(0);

    DB::disableQueryLog();
});
