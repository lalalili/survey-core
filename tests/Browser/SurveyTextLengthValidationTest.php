<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

function browserTextLengthSurvey(string $frontendMode): Survey
{
    config(['survey-core.frontend.css' => $frontendMode]);

    $survey = Survey::create([
        'title' => '文字字數瀏覽器驗證',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'allow_multiple_submissions' => true,
    ]);

    $firstPage = SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'page_short_text',
        'title' => '單行文字',
        'kind' => SurveyPageKind::Question,
        'sort_order' => 1,
    ]);

    $secondPage = SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'page_long_text',
        'title' => '多行文字',
        'kind' => SurveyPageKind::Question,
        'sort_order' => 2,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $firstPage->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '一句話心得',
        'field_key' => 'summary',
        'is_required' => true,
        'validation_rules' => [
            'min_length' => 5,
            'max_length' => 20,
        ],
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $secondPage->id,
        'type' => SurveyFieldType::LongText,
        'label' => '完整心得',
        'field_key' => 'feedback',
        'is_required' => true,
        'validation_rules' => [
            'min_chinese_length' => 4,
            'max_length' => 12,
        ],
        'sort_order' => 2,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $secondPage->id,
        'type' => SurveyFieldType::LongText,
        'label' => '選填補充',
        'field_key' => 'optional_feedback',
        'validation_rules' => [
            'min_length' => 5,
        ],
        'sort_order' => 3,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'survey_page_id' => $secondPage->id,
        'type' => SurveyFieldType::LongText,
        'label' => '隱藏補充',
        'field_key' => 'hidden_feedback',
        'is_required' => true,
        'show_if_field_key' => 'summary',
        'show_if_value' => 'never-shown',
        'validation_rules' => [
            'min_length' => 5,
        ],
        'sort_order' => 4,
    ]);

    return $survey;
}

it('validates text length during blur, page navigation, and submission', function (string $frontendMode) {
    $survey = browserTextLengthSurvey($frontendMode);
    $page = visit(route('survey.show', $survey->public_key));

    $page->assertSee('文字字數瀏覽器驗證')
        ->assertNoJavaScriptErrors()
        ->click('#btn-next')
        ->assertSeeIn('#field-error-summary', '「一句話心得」為必填，請完成填寫。')
        ->assertAttribute('input[name="answers[summary]"]', 'aria-invalid', 'true')
        ->fill('input[name="answers[summary]"]', '短')
        ->click('#main-content')
        ->assertSeeIn('#field-error-summary', '「一句話心得」目前輸入 1 個字，還需要 4 個字。')
        ->assertAttribute('input[name="answers[summary]"]', 'aria-invalid', 'true')
        ->fill('input[name="answers[summary]"]', '足夠五個字')
        ->assertSeeNothingIn('#field-error-summary')
        ->assertAttributeMissing('input[name="answers[summary]"]', 'aria-invalid')
        ->fill('input[name="answers[summary]"]', '短')
        ->click('#btn-next')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.activeElement && document.activeElement.getAttribute("name")', 'answers[summary]')
        ->assertSeeIn('#field-error-summary', '「一句話心得」目前輸入 1 個字，還需要 4 個字。')
        ->assertAttribute('input[name="answers[summary]"]', 'aria-invalid', 'true')
        ->assertVisible('[data-page-key="page_short_text"]')
        ->fill('input[name="answers[summary]"]', '足夠五個字')
        ->assertSeeNothingIn('#field-error-summary')
        ->assertAttributeMissing('input[name="answers[summary]"]', 'aria-invalid')
        ->click('#btn-next')
        ->assertVisible('[data-page-key="page_long_text"]')
        ->assertDisabled('textarea[name="answers[hidden_feedback]"]')
        ->fill('textarea[name="answers[feedback]"]', '中文測試abcdefghijk')
        ->click('#submit-btn')
        ->assertSeeIn('#field-error-feedback', '「完整心得」目前輸入 15 個字，請刪除 3 個字。')
        ->assertAttribute('textarea[name="answers[feedback]"]', 'aria-invalid', 'true')
        ->fill('textarea[name="answers[feedback]"]', '中文ab🙂')
        ->click('#submit-btn')
        ->assertSeeIn('#field-error-feedback', '「完整心得」目前有 2 個中文字，還需要 2 個中文字。')
        ->assertAttribute('textarea[name="answers[feedback]"]', 'aria-invalid', 'true')
        ->fill('textarea[name="answers[feedback]"]', '中文測試內容')
        ->assertSeeNothingIn('#field-error-feedback')
        ->assertAttributeMissing('textarea[name="answers[feedback]"]', 'aria-invalid');

    $page->script(<<<'JS'
window.fetch = async function () {
    return new Response(JSON.stringify({
        message: '感謝您的填寫！',
        response_id: 1,
        response_number: 'TEST-0001',
    }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
    });
};
JS);

    $page
        ->click('#submit-btn')
        ->wait(1)
        ->assertVisible('#success-message')
        ->assertSeeIn('#success-text', '感謝您的填寫！')
        ->assertNoJavaScriptErrors();
})->with([
    'published' => 'published',
    'cdn' => 'cdn',
]);
