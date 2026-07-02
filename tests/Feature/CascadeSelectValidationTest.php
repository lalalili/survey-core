<?php

use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function makeCascadeSurvey(bool $required = false, array $cascadeData = []): Survey
{
    $survey = Survey::create(['title' => 'Cascade Test', 'status' => SurveyStatus::Published]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::CascadeSelect,
        'label' => '地區',
        'field_key' => 'region',
        'is_required' => $required,
        'sort_order' => 1,
        'settings_json' => [
            'cascade_levels' => [
                ['id' => 'level_1', 'label' => '縣市'],
                ['id' => 'level_2', 'label' => '鄉鎮'],
            ],
            'cascade_data' => $cascadeData !== [] ? $cascadeData : [
                [
                    'id' => 'taipei',
                    'label' => '台北市',
                    'children' => [
                        ['id' => 'daan', 'label' => '大安區'],
                        ['id' => 'xinyi', 'label' => '信義區'],
                    ],
                ],
                [
                    'id' => 'taichung',
                    'label' => '台中市',
                    'children' => [
                        ['id' => 'xitun', 'label' => '西屯區'],
                    ],
                ],
            ],
        ],
    ]);

    return $survey->load('fields');
}

it('accepts cascade values that exist in the option tree', function () {
    $survey = makeCascadeSurvey();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => 'taipei', 'level_2' => 'daan']]),
    );

    expect($response->id)->toBeInt();
});

it('rejects a cascade value that does not exist at the first level', function () {
    $survey = makeCascadeSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => 'kaohsiung', 'level_2' => 'daan']]),
    );
})->throws(SurveyValidationException::class);

it('rejects a cascade value that is not a child of the selected parent', function () {
    $survey = makeCascadeSurvey();

    // xitun 屬於 taichung，不屬於 taipei
    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => 'taipei', 'level_2' => 'xitun']]),
    );
})->throws(SurveyValidationException::class);

it('rejects a deep value when a parent level is blank', function () {
    $survey = makeCascadeSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => '', 'level_2' => 'daan']]),
    );
})->throws(SurveyValidationException::class);

it('still requires every level when the field is required', function () {
    $survey = makeCascadeSurvey(required: true);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => 'taipei', 'level_2' => '']]),
    );
})->throws(SurveyValidationException::class);

it('matches nodes by label when the node has no id', function () {
    $survey = makeCascadeSurvey(cascadeData: [
        ['label' => '北部', 'children' => [['label' => '台北市']]],
    ]);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => '北部', 'level_2' => '台北市']]),
    );

    expect($response->id)->toBeInt();
});

it('skips value validation when no cascade data is configured', function () {
    $survey = makeCascadeSurvey(cascadeData: []);
    $field = $survey->fields->first();
    $field->update([
        'settings_json' => array_merge($field->settings_json, ['cascade_data' => []]),
    ]);
    $survey->load('fields');

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['region' => ['level_1' => 'anything', 'level_2' => 'whatever']]),
    );

    expect($response->id)->toBeInt();
});
