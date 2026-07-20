<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

/**
 * 迴歸測試：顯示條件的題目在目標題目未作答時不可顯示。
 *
 * 2026-07-20 線上問卷 13 的症狀是「還沒選擇是否有問題，被條件控制的題目就已顯示」。
 * 根因在前端 getAnswerValue()：radio group 沒有任何 :checked 時，fallback
 * `document.querySelector('[name=...]').value` 會取到 DOM 中第一個選項的 value，
 * 而該問卷的條件正是 equals 第一個選項，於是條件在未作答時就成立。
 */
function makeBranchingSurvey(): Survey
{
    $survey = Survey::create([
        'title' => 'Branching',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => '是否有遇到問題',
        'field_key' => 'has_issue',
        'options_json' => [
            ['label' => '有問題', 'value' => 'yes'],
            ['label' => '沒問題', 'value' => 'no'],
        ],
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::LongText,
        'label' => '請描述問題',
        'field_key' => 'issue_detail',
        'show_if_field_key' => 'has_issue',
        'show_if_value' => 'yes',
        'sort_order' => 2,
    ]);

    return $survey;
}

it('renders the branching rule for the conditional field', function () {
    $survey = makeBranchingSurvey();

    $html = $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('"issue_detail":{"logic":"and","conditions":[{"field_key":"has_issue","op":"equals","value":"yes"}]}');
});

it('treats an unanswered choice group as unanswered instead of falling back to the first option', function () {
    $survey = makeBranchingSurvey();

    $html = $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->getContent();

    // 條件比對的值就是第一個選項，正是會被 fallback 誤中的情況。
    expect($html)->toContain('value="yes"');

    // getAnswerValue() 必須在 radio/checkbox 未勾選時回傳 null，
    // 不可沿用讀取第一個元素 .value 的 fallback。
    expect($html)->toContain("if (inp.type === 'radio' || inp.type === 'checkbox') { return null; }");
});

it('guards the front-end condition evaluator against unanswered questions', function () {
    $survey = makeBranchingSurvey();

    $html = $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->getContent();

    // 與後端 ConditionGroupEvaluator 一致：未作答時除 is_empty / is_not_empty
    // 外一律不成立。少了這道守衛，not_equals / not_contains 會在未作答時成立，
    // less_than 也會因為 Number(null) === 0 而誤判。
    expect($html)->toContain('if (isUnanswered(current)) { return false; }');
});
