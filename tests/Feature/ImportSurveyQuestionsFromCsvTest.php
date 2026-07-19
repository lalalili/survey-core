<?php

use Lalalili\SurveyCore\Actions\ImportSurveyQuestionsFromCsvAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

function importCsv(Survey $survey, string $csv): int
{
    return app(ImportSurveyQuestionsFromCsvAction::class)->execute($survey, $csv);
}

it('imports questions from a CSV into a new question page', function () {
    $survey = Survey::create(['title' => 'CSV', 'status' => SurveyStatus::Draft]);

    $csv = <<<'CSV'
    type,label,required,options,description
    short_text,你的名字,1,,請填寫全名
    single_choice,最喜歡的顏色,0,紅|綠|藍,
    number,年齡,1,,
    CSV;

    $count = importCsv($survey, $csv);
    // 匯入只寫 draft_schema；要看到 survey_fields 必須先發佈。
    app(PublishSurveyAction::class)->execute($survey);

    expect($count)->toBe(3);

    $fields = $survey->fields()->orderBy('sort_order')->get();
    expect($fields)->toHaveCount(3);

    $name = $fields->firstWhere('label', '你的名字');
    expect($name->type)->toBe(SurveyFieldType::ShortText)
        ->and($name->is_required)->toBeTrue();

    $colour = $fields->firstWhere('label', '最喜歡的顏色');
    expect($colour->type)->toBe(SurveyFieldType::SingleChoice)
        ->and($colour->is_required)->toBeFalse()
        ->and(collect($colour->normalizedOptions())->pluck('label')->all())->toBe(['紅', '綠', '藍']);
});

it('skips rows with unknown type, empty label, or choice without options', function () {
    $survey = Survey::create(['title' => 'CSV', 'status' => SurveyStatus::Draft]);

    $csv = <<<'CSV'
    type,label,required,options
    unknown_type,被略過,1,
    short_text,,1,
    single_choice,沒有選項,1,
    long_text,有效題,0,
    CSV;

    $count = importCsv($survey, $csv);
    app(PublishSurveyAction::class)->execute($survey);

    expect($count)->toBe(1)
        ->and($survey->fields()->count())->toBe(1)
        ->and($survey->fields()->first()->label)->toBe('有效題');
});

it('returns zero for an empty or header-only CSV', function () {
    $survey = Survey::create(['title' => 'CSV', 'status' => SurveyStatus::Draft]);

    expect(importCsv($survey, ''))->toBe(0)
        ->and(importCsv($survey, 'type,label,required,options'))->toBe(0)
        ->and($survey->fields()->count())->toBe(0);
});
