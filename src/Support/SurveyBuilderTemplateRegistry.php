<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Support\Arr;

class SurveyBuilderTemplateRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'event_registration' => $this->template(
                slug: 'event_registration',
                name: '活動報名',
                category: '行銷活動',
                elements: [
                    $this->shortText('name', '姓名', true, '請輸入姓名'),
                    $this->shortText('email', 'Email', true, 'name@example.com', ['input_format' => 'email', 'input_mode' => 'email']),
                    $this->shortText('mobile', '手機', true, '0912345678', $this->mobileSettings()),
                    $this->singleChoice('session', '報名場次', true, ['上午場', '下午場', '線上參加']),
                    $this->longText('note', '備註', false, '飲食限制、同行人數或其他需求'),
                ],
            ),
            'satisfaction_survey' => $this->template(
                slug: 'satisfaction_survey',
                name: '滿意度調查',
                category: '顧客回饋',
                elements: [
                    $this->nps('overall_satisfaction', '整體滿意度'),
                    $this->rating('service_rating', '服務態度評分'),
                    $this->rating('quality_rating', '產品品質評分'),
                    $this->longText('improvement_feedback', '希望我們改進的地方', false),
                ],
            ),
            'nps_feedback' => $this->template(
                slug: 'nps_feedback',
                name: 'NPS 淨推薦值',
                category: '顧客回饋',
                elements: [
                    $this->nps('recommend_score', '您有多大可能推薦我們給朋友或同事？'),
                    $this->longText('recommend_reason', '請簡述這個分數的原因', false),
                    $this->singleChoice('contact_permission', '是否願意讓我們進一步聯繫？', false, ['願意', '暫不需要']),
                ],
            ),
            'course_feedback' => $this->template(
                slug: 'course_feedback',
                name: '課程回饋',
                category: '教育課程',
                elements: [
                    $this->rating('course_content_rating', '課程內容實用度'),
                    $this->rating('instructor_rating', '講師表達清楚度'),
                    $this->singleChoice('course_pace', '課程節奏', true, ['太快', '剛好', '太慢']),
                    $this->longText('course_suggestion', '課程建議', false),
                ],
            ),
            'lead_capture' => $this->template(
                slug: 'lead_capture',
                name: '名單蒐集',
                category: '行銷活動',
                elements: [
                    $this->shortText('name', '姓名', true, '請輸入姓名'),
                    $this->shortText('email', 'Email', true, 'name@example.com', ['input_format' => 'email', 'input_mode' => 'email']),
                    $this->shortText('mobile', '手機', true, '0912345678', $this->mobileSettings()),
                    $this->multipleChoice('interests', '感興趣的主題', true, ['產品資訊', '優惠活動', '課程講座', '顧問諮詢']),
                    $this->singleChoice('contact_time', '方便聯繫時段', false, ['上午', '下午', '晚上']),
                ],
            ),
            'after_sales_follow_up' => $this->template(
                slug: 'after_sales_follow_up',
                name: '售後追蹤',
                category: '售後服務',
                elements: [
                    $this->nps('service_recommend_score', '您有多大可能推薦我們的售後服務？'),
                    $this->singleChoice('issue_resolved', '問題是否已解決？', true, ['已解決', '部分解決', '尚未解決']),
                    $this->rating('response_speed_rating', '回覆速度評分'),
                    $this->longText('service_feedback', '請描述您的售後體驗', false),
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $slug): array
    {
        $template = $this->find($slug);

        if ($template === null) {
            throw new \InvalidArgumentException("Survey template [{$slug}] does not exist.");
        }

        return Arr::get($template, 'schema');
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private function template(string $slug, string $name, string $category, array $elements): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'description' => "{$name}範本",
            'schema' => [
                'id' => null,
                'title' => $name,
                'status' => 'draft',
                'version' => 1,
                'settings' => [
                    'progress' => [
                        'mode' => 'bar',
                        'show_estimated_time' => true,
                    ],
                    'show_question_numbers' => true,
                    'allow_back' => true,
                    'language' => 'zh-TW',
                    'uniqueness_mode' => 'none',
                    'anomaly' => [
                        'min_seconds' => null,
                        'detect_duplicate' => 'cookie',
                        'turnstile' => false,
                    ],
                ],
                'pages' => [
                    [
                        'id' => 'page_1',
                        'kind' => 'question',
                        'title' => '第 1 頁',
                        'jump_rules' => [],
                        'elements' => $elements,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function shortText(string $key, string $label, bool $required, string $placeholder = '', array $settings = []): array
    {
        return $this->element($key, 'short_text', $label, $required, placeholder: $placeholder, settings: $settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function longText(string $key, string $label, bool $required, string $placeholder = ''): array
    {
        return $this->element($key, 'long_text', $label, $required, placeholder: $placeholder);
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function singleChoice(string $key, string $label, bool $required, array $labels): array
    {
        return $this->element($key, 'single_choice', $label, $required, options: $this->options($key, $labels));
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function multipleChoice(string $key, string $label, bool $required, array $labels): array
    {
        return $this->element($key, 'multiple_choice', $label, $required, options: $this->options($key, $labels));
    }

    /**
     * @return array<string, mixed>
     */
    private function rating(string $key, string $label): array
    {
        return $this->element($key, 'rating', $label, true, settings: ['count' => 5, 'shape' => 'star']);
    }

    /**
     * @return array<string, mixed>
     */
    private function nps(string $key, string $label): array
    {
        return $this->element($key, 'nps', $label, true, settings: [
            'low_label' => '非常不推薦',
            'high_label' => '非常推薦',
            'color_bands' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function element(string $key, string $type, string $label, bool $required, string $placeholder = '', array $options = [], array $settings = []): array
    {
        return [
            'id' => 'q_'.$key,
            'type' => $type,
            'field_key' => $key,
            'label' => $label,
            'description' => '',
            'required' => $required,
            'placeholder' => $placeholder,
            'options' => $options,
            'settings' => $settings,
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return list<array<string, mixed>>
     */
    private function options(string $key, array $labels): array
    {
        return array_map(
            fn (string $label, int $index): array => [
                'id' => "opt_{$key}_{$index}",
                'label' => $label,
                'value' => "option_{$index}",
            ],
            $labels,
            array_keys($labels),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileSettings(): array
    {
        return [
            'input_format' => 'mobile_tw',
            'input_mode' => 'numeric',
            'minlength' => 10,
            'maxlength' => 10,
            'pattern' => '09[0-9]{8}',
        ];
    }
}
