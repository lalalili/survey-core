<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyPageKind;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;

function makeXssSurvey(): Survey
{
    $survey = Survey::create(['title' => 'XSS Test', 'status' => SurveyStatus::Published]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => 'Name',
        'field_key' => 'name',
        'is_required' => false,
        'sort_order' => 1,
    ]);

    return $survey;
}

it('strips script tags from description block on the public page', function () {
    $survey = makeXssSurvey();

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::DescriptionBlock,
        'label' => 'Info',
        'field_key' => 'info',
        'is_required' => false,
        'description' => '<p>安全內容</p><script>alert("xss")</script>',
        'sort_order' => 2,
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertSee('安全內容')
        ->assertDontSee('alert("xss")', false);
});

it('strips event handler attributes from description block on the public page', function () {
    $survey = makeXssSurvey();

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::DescriptionBlock,
        'label' => 'Info',
        'field_key' => 'info',
        'is_required' => false,
        'description' => '<p onmouseover="alert(1)">內容</p><img src="x" onerror="alert(2)">',
        'sort_order' => 2,
    ]);

    $response = $this->get(route('survey.show', $survey->public_key))->assertOk();

    expect($response->getContent())
        ->not->toContain('onmouseover')
        ->not->toContain('onerror');
});

it('strips script tags from welcome page content', function () {
    $survey = makeXssSurvey();

    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'welcome',
        'title' => '歡迎頁',
        'kind' => SurveyPageKind::Welcome,
        'sort_order' => 0,
        'settings_json' => [
            'welcome' => [
                'enabled' => true,
                'content' => '<p>歡迎</p><script>alert("welcome-xss")</script>',
            ],
        ],
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertSee('歡迎')
        ->assertDontSee('alert("welcome-xss")', false);
});

it('strips script tags from thank you message', function () {
    $survey = makeXssSurvey();

    SurveyPage::create([
        'survey_id' => $survey->id,
        'page_key' => 'thanks',
        'title' => '感謝頁',
        'kind' => SurveyPageKind::ThankYou,
        'sort_order' => 99,
        'settings_json' => [
            'thank_you' => [
                'enabled' => true,
                'message' => '<p>感謝填寫</p><script>alert("thanks-xss")</script>',
            ],
        ],
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertSee('感謝填寫')
        ->assertDontSee('alert("thanks-xss")', false);
});
