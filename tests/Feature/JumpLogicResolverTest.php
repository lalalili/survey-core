<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Support\JumpLogicResolver;

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeJumpSurvey(): Survey
{
    $survey = Survey::create([
        'title' => 'Jump Survey',
        'status' => SurveyStatus::Published,
    ]);

    $pageA = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'Page A', 'sort_order' => 1]);
    $pageB = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_b', 'title' => 'Page B', 'sort_order' => 2]);
    $pageC = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_c', 'title' => 'Page C', 'sort_order' => 3]);

    // Page A: single_choice with jump actions
    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $pageA->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Route',
        'field_key' => 'route',
        'is_required' => true,
        'sort_order' => 1,
        'options_json' => [
            ['id' => 'o1', 'label' => 'A→C', 'value' => 'skip',  'action' => ['type' => 'go_to_page', 'target_page_id' => 'page_c']],
            ['id' => 'o2', 'label' => 'A→B', 'value' => 'next',  'action' => ['type' => 'next_page']],
            ['id' => 'o3', 'label' => 'End',  'value' => 'stop',  'action' => ['type' => 'end_survey']],
        ],
    ]);

    // Page B: plain short_text
    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $pageB->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Page B answer',
        'field_key' => 'page_b_ans',
        'is_required' => false,
        'sort_order' => 2,
    ]);

    // Page C: plain short_text
    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $pageC->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Page C answer',
        'field_key' => 'page_c_ans',
        'is_required' => false,
        'sort_order' => 3,
    ]);

    return $survey->load('pages', 'fields');
}

// ── No jump logic ─────────────────────────────────────────────────────────────

it('returns null when the survey has no pages', function () {
    $survey = Survey::create(['title' => 'No Pages', 'status' => SurveyStatus::Published]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'name',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    expect(JumpLogicResolver::resolveVisitedPages($survey->load('pages', 'fields'), []))->toBeNull();
});

it('returns null when no field has a non-trivial jump action', function () {
    $survey = Survey::create(['title' => 'Plain', 'status' => SurveyStatus::Published]);

    $page1 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'p1', 'title' => 'P1', 'sort_order' => 1]);
    $page2 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'p2', 'title' => 'P2', 'sort_order' => 2]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page1->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Q',
        'field_key' => 'q',
        'is_required' => true,
        'sort_order' => 1,
        'options_json' => [
            ['id' => 'o1', 'label' => 'Yes', 'value' => 'yes', 'action' => ['type' => 'next_page']],
            ['id' => 'o2', 'label' => 'No',  'value' => 'no',  'action' => ['type' => 'next_page']],
        ],
    ]);

    expect(JumpLogicResolver::resolveVisitedPages($survey->load('pages', 'fields'), []))->toBeNull();
});

// ── next_page (default flow) ───────────────────────────────────────────────────

it('visits all pages when answering next_page at every step', function () {
    $survey = makeJumpSurvey();

    $visited = JumpLogicResolver::resolveVisitedPages($survey, ['route' => 'next']);

    $pageIds = $survey->pages->sortBy('sort_order')->pluck('id')->all();
    expect($visited)->toBe($pageIds);
});

// ── go_to_page (skip) ──────────────────────────────────────────────────────────

it('skips page B when the jump action points to page_c', function () {
    $survey = makeJumpSurvey();

    $visited = JumpLogicResolver::resolveVisitedPages($survey, ['route' => 'skip']);

    $pageA = $survey->pages->firstWhere('page_key', 'page_a');
    $pageC = $survey->pages->firstWhere('page_key', 'page_c');
    expect($visited)->toBe([$pageA->id, $pageC->id]);
});

// ── end_survey ────────────────────────────────────────────────────────────────

it('stops at page A when the answer triggers end_survey', function () {
    $survey = makeJumpSurvey();

    $visited = JumpLogicResolver::resolveVisitedPages($survey, ['route' => 'stop']);

    $pageA = $survey->pages->firstWhere('page_key', 'page_a');
    expect($visited)->toBe([$pageA->id]);
});

// ── Unanswered jump field ─────────────────────────────────────────────────────

it('falls through to default next page when the jump field has no answer', function () {
    $survey = makeJumpSurvey();

    $visited = JumpLogicResolver::resolveVisitedPages($survey, []);

    $pageIds = $survey->pages->sortBy('sort_order')->pluck('id')->all();
    expect($visited)->toBe($pageIds);
});

// ── select type ───────────────────────────────────────────────────────────────

it('follows go_to_page jump action on a select field', function () {
    $survey = Survey::create(['title' => 'Select Jump', 'status' => SurveyStatus::Published]);

    $page1 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'sp1', 'title' => 'P1', 'sort_order' => 1]);
    $page2 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'sp2', 'title' => 'P2', 'sort_order' => 2]);
    $page3 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'sp3', 'title' => 'P3', 'sort_order' => 3]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page1->id,
        'type' => SurveyFieldType::Select,
        'label' => 'Region',
        'field_key' => 'region',
        'is_required' => true,
        'sort_order' => 1,
        'options_json' => [
            ['id' => 'o1', 'label' => 'North', 'value' => 'north', 'action' => ['type' => 'go_to_page', 'target_page_id' => 'sp3']],
            ['id' => 'o2', 'label' => 'South', 'value' => 'south', 'action' => ['type' => 'next_page']],
        ],
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page2->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'P2 Q',
        'field_key' => 'p2_q',
        'is_required' => false,
        'sort_order' => 2,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $page3->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'P3 Q',
        'field_key' => 'p3_q',
        'is_required' => false,
        'sort_order' => 3,
    ]);

    $visited = JumpLogicResolver::resolveVisitedPages($survey->load('pages', 'fields'), ['region' => 'north']);

    expect($visited)->toBe([$page1->id, $page3->id]);
});

/**
 * 空的 condition 不可觸發跳轉。ConditionGroupEvaluator 對空的 conditions
 * 回傳 true（顯示條件語意是「沒設條件就顯示」），若頁面跳轉規則沿用該語意，
 * 一條還沒設定條件的規則就會無條件改變問卷流程。
 */
function makePageJumpSurvey(array $jumpRules): array
{
    $survey = Survey::create(['title' => 'Page Jump', 'status' => SurveyStatus::Published]);

    $page1 = SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'jp1',
        'title' => 'P1',
        'sort_order' => 1,
        'settings_json' => ['jump_rules' => $jumpRules],
    ]);
    $page2 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'jp2', 'title' => 'P2', 'sort_order' => 2]);
    $page3 = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'jp3', 'title' => 'P3', 'sort_order' => 3]);

    foreach ([[$page1, 'jq1'], [$page2, 'jq2'], [$page3, 'jq3']] as [$page, $key]) {
        SurveyField::create([
            'survey_id' => $survey->id,
            'survey_page_id' => $page->id,
            'type' => SurveyFieldType::ShortText,
            'label' => $key,
            'field_key' => $key,
            'is_required' => false,
            'sort_order' => 1,
        ]);
    }

    return [$survey, $page1, $page2, $page3];
}

it('ignores a page jump rule that carries no conditions', function (mixed $condition): void {
    [$survey, $page1, $page2, $page3] = makePageJumpSurvey([
        ['condition' => $condition, 'action' => ['type' => 'go_to_page', 'target_page_id' => 'jp3']],
    ]);

    $visited = JumpLogicResolver::resolveVisitedPages($survey->load('pages', 'fields'), []);

    // 規則被略過 → 走預設的下一頁，而不是跳到 P3
    expect($visited)->toBe([$page1->id, $page2->id, $page3->id]);
})->with([
    'empty array' => [[]],
    'empty conditions' => [['logic' => 'and', 'conditions' => []]],
    'missing conditions' => [['logic' => 'and']],
]);

it('still applies a page jump rule that has real conditions', function (): void {
    [$survey, $page1, $page2, $page3] = makePageJumpSurvey([
        [
            'condition' => ['logic' => 'and', 'conditions' => [['field_key' => 'jq1', 'op' => 'equals', 'value' => 'skip']]],
            'action' => ['type' => 'go_to_page', 'target_page_id' => 'jp3'],
        ],
    ]);

    $loaded = $survey->load('pages', 'fields');

    expect(JumpLogicResolver::resolveVisitedPages($loaded, ['jq1' => 'skip']))->toBe([$page1->id, $page3->id])
        ->and(JumpLogicResolver::resolveVisitedPages($loaded, ['jq1' => 'stay']))->toBe([$page1->id, $page2->id, $page3->id]);
});
