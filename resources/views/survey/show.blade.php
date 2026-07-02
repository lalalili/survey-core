<!DOCTYPE html>
<html lang="{{ $survey->settings_json['language'] ?? 'zh-TW' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $survey->title }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $theme = $theme ?? [];
        $optionUsage = $optionUsage ?? [];
        $cssMode = config('survey-core.frontend.css', 'cdn');
        // 儲存端已淨化（SanitizeSurveyBuilderSchemaAction），此處為顯示端第二道防線，涵蓋 JSON 匯入等未經 Builder 的寫入路徑
        $sanitizeHtml = fn (?string $html): string => app(\Lalalili\SurveyCore\Support\SurveyHtmlSanitizer::class)->clean($html) ?? '';
        $fileUploadFormatGroups = [
            '文件' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
            '簡報' => ['ppt', 'pptx'],
            '試算表' => ['xls', 'xlsx', 'csv'],
            '圖片' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'],
            '影片' => ['mpg', 'mpeg', 'mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm'],
            '音樂' => ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac'],
        ];
        $fileUploadAllowedExtensions = function ($field): array {
            return array_values(array_filter(array_map(
                fn ($extension) => ltrim(trim((string) $extension), '.'),
                (array) ($field->settings_json['allowed_mimes'] ?? []),
            )));
        };
        $fileUploadAccept = fn ($field): ?string => ($extensions = $fileUploadAllowedExtensions($field)) === []
            ? null
            : implode(',', array_map(fn (string $extension): string => '.'.$extension, $extensions));
        $fileUploadSizeLabel = fn ($field): string => ((int) ($field->settings_json['max_size_mb'] ?? 0)) > 0
            ? ((int) ($field->settings_json['max_size_mb'] ?? 0)).' MB以下'
            : '未限制大小';
        $fileUploadFormatLabel = function ($field) use ($fileUploadAllowedExtensions, $fileUploadFormatGroups): string {
            $extensions = $fileUploadAllowedExtensions($field);

            if ($extensions === []) {
                return '不限';
            }

            $labels = [];
            $known = [];

            foreach ($fileUploadFormatGroups as $label => $groupExtensions) {
                if (array_intersect($groupExtensions, $extensions) !== []) {
                    $labels[] = $label;
                    $known = array_merge($known, $groupExtensions);
                }
            }

            $custom = array_values(array_diff($extensions, $known));

            return implode('、', array_values(array_unique(array_merge($labels, $custom))));
        };
    @endphp

    @if($cssMode === 'cdn')
        <script src="https://cdn.tailwindcss.com"></script>
    @elseif($cssMode === 'published')
        <link rel="stylesheet" href="{{ asset('vendor/survey-core/survey.css') }}">
    @else
        <link rel="stylesheet" href="{{ $cssMode }}">
    @endif
    @include('survey-core::survey.partials.style')
    @if(!empty(config('survey-core.turnstile.site_key')))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
</head>
<body>

<a href="#main-content" class="survey-skip-link">跳至主要內容</a>

@php
    // ── Page / field data ────────────────────────────────────────────────────
    $usePageModel = $survey->pages->isNotEmpty();

    if ($usePageModel) {
        // survey_pages normalised model
        $surveyPages = $survey->pages; // sorted by sort_order
        $fieldsByPageId = $survey->fields
            ->where('is_hidden', false)
            ->groupBy('survey_page_id');

        $welcomePage = $surveyPages->first(fn ($p) => ($p->kind?->value ?? 'question') === 'welcome');
        $thankYouPage = $surveyPages->first(fn ($p) => ($p->kind?->value ?? 'question') === 'thank_you');
        $questionPages = $surveyPages->filter(fn ($p) => ($p->kind?->value ?? 'question') === 'question')->values();
        $allQuestionPageKeys = $questionPages->pluck('page_key')->values()->all();

        $renderPages = $questionPages->map(fn ($p) => [
            'key'    => $p->page_key,
            'title'  => $p->title,
            'fields' => collect(\Lalalili\SurveyCore\Models\SurveyField::arrangeForDisplay(
                ($fieldsByPageId[$p->id] ?? collect())->sortBy('sort_order')->values()->all(),
                $shuffleSeed ?? null,
            )),
        ])->filter(fn ($page) => $page['fields']->isNotEmpty())->values();

        $pagesData = $renderPages->map(fn ($page) => ['id' => $page['key'], 'title' => $page['title']])->values()->all();
    } else {
        // Fallback: group by integer page field (legacy / un-synced surveys)
        $allFields = $survey->fields->where('is_hidden', false)->sortBy('sort_order');
        $grouped   = $allFields->groupBy(fn ($f) => $f->page ?? 1)->sortKeys();
        $allQuestionPageKeys = $grouped->keys()->map(fn ($num) => 'page_' . $num)->values()->all();

        $renderPages = $grouped->map(fn ($fields, $num) => [
            'key'    => 'page_' . $num,
            'title'  => '第 ' . $num . ' 頁',
            'fields' => collect(\Lalalili\SurveyCore\Models\SurveyField::arrangeForDisplay(
                $fields->values()->all(),
                $shuffleSeed ?? null,
            )),
        ])->values();

        $pagesData = $renderPages->map(fn ($rp) => ['id' => $rp['key'], 'title' => $rp['title']])->values()->all();
        $welcomePage = null;
        $thankYouPage = null;
    }

    $isMultiPage = count($pagesData) > 1;
    $pageCount   = count($pagesData);
    $showQuestionNumbers = (bool) ($survey->settings_json['show_question_numbers'] ?? true);
    $allowBack = (bool) ($survey->settings_json['allow_back'] ?? true);
    $progressSettings = $survey->settings_json['progress'] ?? ['mode' => 'bar', 'show_estimated_time' => true];
    $progressMode = $progressSettings['mode'] ?? 'bar';
    $showEstimatedTime = (bool) ($progressSettings['show_estimated_time'] ?? true);
    $welcomeSettings  = $welcomePage?->settings_json['welcome'] ?? [];
    $thankYouSettings = $thankYouPage?->settings_json['thank_you'] ?? [];
    $hasWelcomePage   = $welcomePage !== null && ($welcomeSettings['enabled'] ?? true) !== false;
    $hasThankYouPage  = $thankYouPage !== null && ($thankYouSettings['enabled'] ?? true) !== false;

    // ── Thank-you redirect (survey-level; falls back to legacy thank-you-page setting) ──
    $surveySettings = $survey->settings();
    $redirectConfig = $surveySettings->redirectUrl !== null ? [
        'url' => $surveySettings->redirectUrl,
        'mode' => $surveySettings->redirectMode,
        'delay' => $surveySettings->redirectDelaySeconds,
    ] : null;
    if ($redirectConfig === null && $hasThankYouPage && ! empty($thankYouSettings['redirect_url'])) {
        $redirectConfig = ['url' => (string) $thankYouSettings['redirect_url'], 'mode' => 'link', 'delay' => 5];
    }

    // ── Access controls ──────────────────────────────────────────────────────
    $hasPassword      = !empty($survey->settings_json['password'] ?? null) && empty($passwordUnlocked);
    $surveyQuery      = array_filter([
        't' => request()->query('t'),
        'collector' => $collector?->slug ?? null,
    ], fn ($value) => $value !== null && $value !== '');
    $termsText        = $survey->settings_json['terms_text'] ?? null;
    $hasTerms         = !empty($termsText);

    // ── Turnstile ────────────────────────────────────────────────────────────
    $turnstileEnabled = !empty($survey->settings_json['anomaly']['turnstile']);
    $turnstiteSiteKey = config('survey-core.turnstile.site_key');

    // ── BRANCHING (show_if) ──────────────────────────────────────────────────
    $branchingMap = $survey->fields
        ->where('is_hidden', false)
        ->filter(fn ($f) => $f->show_if_field_key || is_array($f->settings_json['show_if'] ?? null))
        ->mapWithKeys(fn ($f) => [$f->field_key => $f->settings_json['show_if'] ?? [
            'logic' => 'and',
            'conditions' => [[
                'field_key' => $f->show_if_field_key,
                'op' => 'equals',
                'value' => $f->show_if_value,
            ]],
        ]])
        ->toArray();

    // ── JUMP_MAP {field_key: {option_value: {type, target_page_id?}}} ────────
    $jumpMap = [];
    $pageJumpMap = [];
    foreach ($survey->fields as $field) {
        if (! in_array($field->type->value, ['single_choice', 'select'])) {
            continue;
        }

        if (empty($field->options_json) || ! array_is_list($field->options_json)) {
            continue;
        }

        $map = [];
        foreach ($field->options_json as $opt) {
            $action = $opt['action'] ?? null;
            if (is_array($action) && isset($action['type']) && $action['type'] !== 'next_page') {
                $map[(string) ($opt['value'] ?? '')] = $action;
            }
        }

        if (! empty($map)) {
            $jumpMap[$field->field_key] = $map;
        }
    }

    foreach ($questionPages ?? [] as $page) {
        $rules = $page->settings_json['jump_rules'] ?? [];
        if (is_array($rules) && ! empty($rules)) {
            $pageJumpMap[$page->page_key] = $rules;
        }
    }
@endphp

{{-- ── CDN mode uses Tailwind utilities; published mode uses survey.css classes ── --}}
@if($cssMode === 'cdn')
@include('survey-core::survey.partials.layout-cdn')
@else
@include('survey-core::survey.partials.layout-published')
@endif

@include('survey-core::survey.partials.scripts')
</body>
</html>
