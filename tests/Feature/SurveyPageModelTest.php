<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

// ── Basic CRUD ────────────────────────────────────────────────────────────────

it('creates and retrieves a survey page', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $page = SurveyPage::create([
        'survey_id'  => $survey->id,
        'page_key'   => 'page_intro',
        'title'      => 'Introduction',
        'sort_order' => 1,
    ]);

    expect($page->page_key)->toBe('page_intro')
        ->and($page->title)->toBe('Introduction')
        ->and($page->sort_order)->toBe(1)
        ->and($page->settings_json)->toBeNull();
});

it('enforces unique page_key per survey', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);
    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A dup', 'sort_order' => 2]);
})->throws(Illuminate\Database\QueryException::class);

it('allows the same page_key in different surveys', function () {
    $s1 = Survey::create(['title' => 'S1', 'status' => SurveyStatus::Draft]);
    $s2 = Survey::create(['title' => 'S2', 'status' => SurveyStatus::Draft]);

    SurveyPage::create(['survey_id' => $s1->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);
    SurveyPage::create(['survey_id' => $s2->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);

    expect(SurveyPage::count())->toBe(2);
});

// ── Relationships ─────────────────────────────────────────────────────────────

it('belongs to a survey', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    $page = SurveyPage::create([
        'survey_id'  => $survey->id,
        'page_key'   => 'page_a',
        'title'      => 'A',
        'sort_order' => 1,
    ]);

    expect($page->survey->id)->toBe($survey->id);
});

it('survey pages() relationship returns pages sorted by sort_order', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);

    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_c', 'title' => 'C', 'sort_order' => 3]);
    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);
    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_b', 'title' => 'B', 'sort_order' => 2]);

    $keys = $survey->pages->pluck('page_key')->all();

    expect($keys)->toBe(['page_a', 'page_b', 'page_c']);
});

it('page fields() relationship returns fields sorted by sort_order', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);
    $page = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);

    SurveyField::create(['survey_id' => $survey->id, 'survey_page_id' => $page->id, 'type' => SurveyFieldType::ShortText, 'label' => 'B', 'field_key' => 'b', 'is_required' => false, 'sort_order' => 2]);
    SurveyField::create(['survey_id' => $survey->id, 'survey_page_id' => $page->id, 'type' => SurveyFieldType::ShortText, 'label' => 'A', 'field_key' => 'a', 'is_required' => false, 'sort_order' => 1]);

    $keys = $page->fields->pluck('field_key')->all();

    expect($keys)->toBe(['a', 'b']);
});

// ── Cascade delete ────────────────────────────────────────────────────────────

it('deleting a survey cascades to its pages', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);
    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);

    $survey->delete();

    expect(SurveyPage::count())->toBe(0);
});

it('deleting a page sets survey_page_id to null on its fields', function () {
    $survey = Survey::create(['title' => 'Test', 'status' => SurveyStatus::Draft]);
    $page = SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'page_a', 'title' => 'A', 'sort_order' => 1]);

    $field = SurveyField::create([
        'survey_id'      => $survey->id,
        'survey_page_id' => $page->id,
        'type'           => SurveyFieldType::ShortText,
        'label'          => 'Name',
        'field_key'      => 'name',
        'is_required'    => false,
        'sort_order'     => 1,
    ]);

    $page->delete();

    expect($field->refresh()->survey_page_id)->toBeNull();
});
