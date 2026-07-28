<?php

use Lalalili\SurveyCore\Actions\ValidateSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Actions\ValidateSurveySubmissionAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function minimumChineseLengthSurvey(
    SurveyFieldType $type,
    array $validationRules,
    bool $isRequired = true,
): Survey {
    $survey = Survey::create([
        'title' => '最少中文字數測試',
        'status' => SurveyStatus::Published,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => $type,
        'label' => '使用心得',
        'field_key' => 'feedback',
        'is_required' => $isRequired,
        'validation_rules' => $validationRules,
        'sort_order' => 1,
    ]);

    return $survey->load('fields');
}

function minimumChineseLengthSchema(mixed $minimumChineseLength): array
{
    return [
        'title' => '最少中文字數測試',
        'pages' => [
            [
                'id' => 'page_1',
                'title' => '第一頁',
                'elements' => [
                    [
                        'id' => 'feedback',
                        'type' => SurveyFieldType::ShortText->value,
                        'field_key' => 'feedback',
                        'label' => '使用心得',
                        'required' => false,
                        'options' => [],
                        'settings' => [],
                        'validation_rules' => [
                            'min_chinese_length' => $minimumChineseLength,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

it('accepts Han characters in short and long text answers', function (SurveyFieldType $type, string $answer) {
    $survey = minimumChineseLengthSurvey($type, ['min_chinese_length' => 3]);

    expect(fn () => app(ValidateSurveySubmissionAction::class)->execute(
        $survey,
        ['feedback' => $answer],
    ))->not->toThrow(SurveyValidationException::class);
})->with([
    'short text with traditional and simplified Han' => [SurveyFieldType::ShortText, '中文汉'],
    'long text with a CJK extension character' => [SurveyFieldType::LongText, '中文𠀀'],
    'short text with Japanese Kanji' => [SurveyFieldType::ShortText, '漢字文'],
]);

it('rejects mixed content when text answers contain too few Han characters with the dedicated message', function (SurveyFieldType $type) {
    $survey = minimumChineseLengthSurvey($type, [
        'min_chinese_length' => 2,
        'pattern_label' => '不應覆寫此錯誤',
    ]);

    try {
        app(ValidateSurveySubmissionAction::class)->execute(
            $survey,
            ['feedback' => 'A 中，🙂 123'],
        );
    } catch (SurveyValidationException $exception) {
        expect($exception->getErrors()['feedback'])
            ->toContain('「使用心得」至少需輸入 2 個中文字。')
            ->not->toContain('不應覆寫此錯誤');

        return;
    }

    $this->fail('Expected the minimum Chinese length validation to fail.');
})->with([
    'short text' => SurveyFieldType::ShortText,
    'long text' => SurveyFieldType::LongText,
]);

it('allows an optional text answer to remain empty', function (mixed $answer) {
    $survey = minimumChineseLengthSurvey(
        SurveyFieldType::LongText,
        ['min_chinese_length' => 3],
        false,
    );

    expect(fn () => app(ValidateSurveySubmissionAction::class)->execute(
        $survey,
        ['feedback' => $answer],
    ))->not->toThrow(SurveyValidationException::class);
})->with([
    'null' => null,
    'empty string' => '',
]);

it('applies minimum total length independently from minimum Chinese length', function () {
    $survey = minimumChineseLengthSurvey(SurveyFieldType::LongText, [
        'min_length' => 5,
        'min_chinese_length' => 2,
    ]);

    try {
        app(ValidateSurveySubmissionAction::class)->execute(
            $survey,
            ['feedback' => '中文'],
        );
    } catch (SurveyValidationException $exception) {
        expect($exception->getErrors()['feedback'])
            ->toContain('「使用心得」至少需輸入 5 個字。')
            ->not->toContain('「使用心得」至少需輸入 2 個中文字。');

        return;
    }

    $this->fail('Expected the minimum total length validation to fail.');
});

it('applies maximum total length independently when all text limits are configured', function () {
    $survey = minimumChineseLengthSurvey(SurveyFieldType::ShortText, [
        'min_length' => 2,
        'min_chinese_length' => 2,
        'max_length' => 4,
    ]);

    try {
        app(ValidateSurveySubmissionAction::class)->execute(
            $survey,
            ['feedback' => '中文ABC'],
        );
    } catch (SurveyValidationException $exception) {
        expect($exception->getErrors()['feedback'])
            ->toContain('「使用心得」最多只能輸入 4 個字。')
            ->not->toContain('「使用心得」至少需輸入 2 個字。')
            ->not->toContain('「使用心得」至少需輸入 2 個中文字。');

        return;
    }

    $this->fail('Expected the maximum total length validation to fail.');
});

it('does not apply minimum Chinese length to phone fields', function () {
    $survey = minimumChineseLengthSurvey(SurveyFieldType::Phone, [
        'min_chinese_length' => 1,
    ]);

    expect(fn () => app(ValidateSurveySubmissionAction::class)->execute(
        $survey,
        ['feedback' => '0912345678'],
    ))->not->toThrow(SurveyValidationException::class);
});

it('allows zero or omitted minimum Chinese length without restricting text', function (array $validationRules) {
    $survey = minimumChineseLengthSurvey(SurveyFieldType::ShortText, $validationRules);

    expect(fn () => app(ValidateSurveySubmissionAction::class)->execute(
        $survey,
        ['feedback' => 'English only'],
    ))->not->toThrow(SurveyValidationException::class);
})->with([
    'zero' => [['min_chinese_length' => 0]],
    'omitted' => [[]],
]);

it('rejects invalid minimum Chinese lengths in builder schemas', function (mixed $minimumChineseLength) {
    try {
        app(ValidateSurveyBuilderSchemaAction::class)->execute(
            minimumChineseLengthSchema($minimumChineseLength),
        );
    } catch (SurveyValidationException $exception) {
        expect($exception->getErrors())
            ->toHaveKey('pages.0.elements.0.validation_rules.min_chinese_length');

        return;
    }

    $this->fail('Expected the builder schema validation to fail.');
})->with([
    'negative integer' => -1,
    'decimal number' => 1.5,
]);
