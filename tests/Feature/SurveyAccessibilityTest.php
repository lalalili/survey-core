<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function a11ySurvey(): Survey
{
    $survey = Survey::create([
        'title' => 'A11y',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '您的稱呼',
        'field_key' => 'name',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::MatrixSingle,
        'label' => '滿意度矩陣',
        'field_key' => 'mtx',
        'is_required' => true,
        'settings_json' => [
            'matrix_rows' => [['id' => 'r1', 'label' => '服務態度']],
            'matrix_cols' => [['id' => 'c1', 'label' => '滿意']],
        ],
        'sort_order' => 2,
    ]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Ranking,
        'label' => '偏好排序',
        'field_key' => 'rank',
        'options_json' => [
            ['label' => '甲方案', 'value' => 'a'],
            ['label' => '乙方案', 'value' => 'b'],
        ],
        'sort_order' => 3,
    ]);

    return $survey;
}

it('renders accessibility landmarks on the cdn variant', function () {
    config(['survey-core.frontend.css' => 'cdn']);

    $this->get(route('survey.show', a11ySurvey()->public_key))
        ->assertSuccessful()
        // 錯誤容器讓螢幕報讀器即時播報
        ->assertSee('id="error-banner" role="alert" aria-live="assertive"', false)
        ->assertSee('class="text-xs text-red-500 mt-1 hidden field-error" data-field="mtx" role="alert"', false)
        // 進度資訊
        ->assertSee('role="status" aria-live="polite"', false)
        ->assertSee('aria-label="填答進度"', false)
        // 題目分組與標籤關聯
        ->assertSee('role="group"', false)
        ->assertSee('aria-labelledby="q-label-mtx"', false)
        ->assertSee('id="q-label-mtx"', false)
        ->assertSee('aria-required="true"', false)
        // 矩陣表頭範圍與每格無障礙標籤
        ->assertSee('scope="col"', false)
        ->assertSee('scope="row"', false)
        ->assertSee('aria-label="服務態度：滿意"', false)
        // 單一輸入框具明確可及名稱
        ->assertSee('aria-labelledby="q-label-name"', false)
        // 排序題的鍵盤操作說明與具名按鈕
        ->assertSee('aria-describedby="ranking-help-rank"', false)
        ->assertSee('aria-label="將「甲方案」上移"', false)
        ->assertSee('aria-label="將「乙方案」下移"', false)
        // Skip link 讓鍵盤使用者跳過頁首
        ->assertSee('href="#main-content"', false)
        ->assertSee('id="main-content"', false);
});

it('labels the password gate input', function () {
    config(['survey-core.frontend.css' => 'cdn']);

    $survey = Survey::create([
        'title' => 'Locked',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
        'settings_json' => ['password' => 'secret'],
    ]);
    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::ShortText,
        'label' => '稱呼',
        'field_key' => 'name',
        'sort_order' => 1,
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('aria-label="問卷密碼"', false)
        ->assertSee('aria-describedby="password-error"', false);
});

it('labels rating/nps custom controls and exposes keyboard focus styles', function () {
    config(['survey-core.frontend.css' => 'cdn']);

    $survey = app(PublishSurveyAction::class)->execute(Survey::create([
        'title' => 'Scales',
        'status' => SurveyStatus::Draft,
        'allow_anonymous' => true,
        'draft_schema' => [
            'id' => 'accessible-scales',
            'title' => 'Scales',
            'status' => 'draft',
            'version' => 1,
            'pages' => [[
                'id' => 'page_1',
                'title' => 'Scales',
                'elements' => [
                    [
                        'id' => 'rating_question',
                        'type' => 'rating',
                        'field_key' => 'rate',
                        'label' => '服務評分',
                        'description' => '',
                        'required' => false,
                        'placeholder' => null,
                        'options' => [],
                        'settings' => ['count' => 5, 'shape' => 'star'],
                    ],
                    [
                        'id' => 'nps_question',
                        'type' => 'nps',
                        'field_key' => 'nps',
                        'label' => '推薦意願',
                        'description' => '',
                        'required' => false,
                        'placeholder' => null,
                        'options' => [],
                        'settings' => [],
                    ],
                ],
            ]],
        ],
    ]));

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        // sr-only radio 需可被報讀器命名
        ->assertSee('aria-label="1 分"', false)
        ->assertSee('aria-label="0 分"', false)
        // 鍵盤焦點在可見的 pip/label 上呈現
        ->assertSee('.survey-nps-radio:focus-visible) .survey-nps-pip', false)
        ->assertSee('grid-template-columns: repeat(11, minmax(0, 1fr))', false)
        ->assertSee('min-width: 24px', false)
        ->assertSee('min-height: 36px', false)
        ->assertSee('.survey-rating-star-label:has(.survey-rating-radio:focus-visible)', false)
        // NPS 端點文字對比達標的較深色
        ->assertSee('color: #4b5563', false);

    config(['survey-core.frontend.css' => 'published']);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('value="0"', false)
        ->assertSee('value="10"', false)
        ->assertSee('grid-template-columns: repeat(11, minmax(0, 1fr))', false);

    $publishedCss = file_get_contents(dirname(__DIR__, 2).'/resources/dist/survey.css');

    expect($publishedCss)->toContain('grid-template-columns: repeat(11, minmax(0, 1fr))')
        ->toContain('min-width: 24px')
        ->toContain('min-height: 36px')
        ->not->toContain('.survey-nps-row  { display: flex');
});

it('renders accessibility landmarks on the published variant', function () {
    config(['survey-core.frontend.css' => 'published']);

    $this->get(route('survey.show', a11ySurvey()->public_key))
        ->assertSuccessful()
        ->assertSee('id="error-banner" role="alert" aria-live="assertive"', false)
        ->assertSee('role="group"', false)
        ->assertSee('aria-labelledby="q-label-mtx"', false)
        ->assertSee('aria-required="true"', false)
        ->assertSee('scope="col"', false)
        ->assertSee('scope="row"', false)
        ->assertSee('aria-label="服務態度：滿意"', false);
});
