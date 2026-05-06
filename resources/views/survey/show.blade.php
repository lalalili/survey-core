<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $survey->title }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $theme = $theme ?? [];
        $optionUsage = $optionUsage ?? [];
        $cssMode = config('survey-core.frontend.css', 'cdn');
    @endphp

    @if($cssMode === 'cdn')
        <script src="https://cdn.tailwindcss.com"></script>
    @elseif($cssMode === 'published')
        <link rel="stylesheet" href="{{ asset('vendor/survey-core/survey.css') }}">
    @else
        <link rel="stylesheet" href="{{ $cssMode }}">
    @endif
    <style>
        :root {
            --survey-primary: {{ $theme['primary'] ?? '#6366f1' }};
            --survey-accent: {{ $theme['accent'] ?? '#f59e0b' }};
            --survey-background: {{ $theme['background'] ?? '#ffffff' }};
            --survey-surface: {{ $theme['surface'] ?? '#f9fafb' }};
            --survey-text: {{ $theme['text'] ?? '#111827' }};
            --survey-text-muted: {{ $theme['text_muted'] ?? '#6b7280' }};
            --survey-border: {{ $theme['border'] ?? '#e5e7eb' }};
            --survey-font: {{ $theme['font_family'] ?? 'system-ui, sans-serif' }};
            --survey-radius: {{ $theme['radius'] ?? '0.5rem' }};
        }

        body {
            background: var(--survey-background);
            color: var(--survey-text);
            font-family: var(--survey-font);
        }

        .survey-themed-primary {
            background: var(--survey-primary) !important;
            border-color: var(--survey-primary) !important;
        }
    </style>
    @if(!empty(config('survey-core.turnstile.site_key')))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
</head>
<body>

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

        $renderPages = $questionPages->map(fn ($p) => [
            'key'    => $p->page_key,
            'title'  => $p->title,
            'fields' => ($fieldsByPageId[$p->id] ?? collect())->sortBy('sort_order')->values(),
        ]);

        $pagesData = $questionPages->map(fn ($p) => ['id' => $p->page_key, 'title' => $p->title])->values()->all();
    } else {
        // Fallback: group by integer page field (legacy / un-synced surveys)
        $allFields = $survey->fields->where('is_hidden', false)->sortBy('sort_order');
        $grouped   = $allFields->groupBy(fn ($f) => $f->page ?? 1)->sortKeys();

        $renderPages = $grouped->map(fn ($fields, $num) => [
            'key'    => 'page_' . $num,
            'title'  => '第 ' . $num . ' 頁',
            'fields' => $fields->values(),
        ])->values();

        $pagesData = $renderPages->map(fn ($rp) => ['id' => $rp['key'], 'title' => $rp['title']])->values()->all();
        $welcomePage = null;
        $thankYouPage = null;
    }

    $isMultiPage = count($pagesData) > 1;
    $pageCount   = count($pagesData);
    $progressSettings = $survey->settings_json['progress'] ?? ['mode' => 'bar', 'show_estimated_time' => true];
    $progressMode = $progressSettings['mode'] ?? 'bar';
    $showEstimatedTime = (bool) ($progressSettings['show_estimated_time'] ?? true);
    $welcomeSettings  = $welcomePage?->settings_json['welcome'] ?? [];
    $thankYouSettings = $thankYouPage?->settings_json['thank_you'] ?? [];
    $hasWelcomePage   = $welcomePage !== null && ($welcomeSettings['enabled'] ?? true) !== false;
    $hasThankYouPage  = $thankYouPage !== null && ($thankYouSettings['enabled'] ?? true) !== false;

    // ── Access controls ──────────────────────────────────────────────────────
    $surveyPassword   = $survey->settings_json['password'] ?? null;
    $hasPassword      = !empty($surveyPassword);
    $termsText        = $survey->settings_json['terms_text'] ?? null;
    $hasTerms         = !empty($termsText);
    $enableResponseNo = !empty($survey->settings_json['response_number']);

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

{{-- ======================================================= TAILWIND CDN LAYOUT ===== --}}
<div class="bg-gray-50 min-h-screen py-10" style="background: var(--survey-background); color: var(--survey-text);">
<div class="max-w-2xl mx-auto px-4">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">{{ $survey->title }}</h1>
        @if($survey->description)
            <p class="mt-2 text-gray-600 whitespace-pre-line">{{ $survey->description }}</p>
        @endif
    </div>

    {{-- Password Gate --}}
    @if($hasPassword)
    <div id="password-gate" class="rounded-lg bg-white border border-gray-200 p-8 shadow-sm" style="background: var(--survey-surface); border-color: var(--survey-border); border-radius: var(--survey-radius);">
        <h2 class="text-xl font-bold text-gray-900 mb-2" style="color: var(--survey-text);">此問卷設有密碼保護</h2>
        <p class="text-sm text-gray-500 mb-5" style="color: var(--survey-text-muted);">請輸入密碼以繼續填寫</p>
        <div class="flex gap-3">
            <input id="password-input" type="password" placeholder="輸入密碼"
                class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                style="border-color: var(--survey-border);">
            <button type="button" id="btn-unlock" class="survey-themed-primary inline-flex items-center rounded-md bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:opacity-90">
                解鎖
            </button>
        </div>
        <p id="password-error" class="hidden mt-2 text-sm text-red-600">密碼不正確，請重試。</p>
    </div>
    <div id="after-gate" class="hidden">
    @endif

    {{-- Welcome --}}
    @if($hasWelcomePage && $welcomePage)
    <div id="welcome-screen" class="rounded-lg bg-white border border-gray-200 p-8 text-center shadow-sm" style="background: var(--survey-surface); border-color: var(--survey-border); border-radius: var(--survey-radius);">
        @if(!empty($welcomePage->title))
        <h2 class="text-2xl font-bold text-gray-900" style="color: var(--survey-text);">{{ $welcomePage->title }}</h2>
        @endif
        @if(!empty($welcomeSettings['subtitle']))
            <p class="mt-3 text-gray-600" style="color: var(--survey-text-muted);">{{ $welcomeSettings['subtitle'] }}</p>
        @endif
        @if(!empty($welcomeSettings['content']))
            <div class="mt-4 text-left survey-rich-content" style="color: var(--survey-text);">{!! $welcomeSettings['content'] !!}</div>
        @endif
        @if($showEstimatedTime && (int) ($welcomeSettings['estimated_time_minutes'] ?? 0) > 0)
            <p class="mt-4 text-sm text-gray-500" style="color: var(--survey-text-muted);">預估填寫時間：約 {{ (int) $welcomeSettings['estimated_time_minutes'] }} 分鐘</p>
        @endif
        <button type="button" id="btn-start" class="survey-themed-primary mt-6 inline-flex items-center justify-center rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90">
            {{ $welcomeSettings['cta_label'] ?? '開始填寫' }}
        </button>
    </div>
    @endif

    {{-- Success --}}
    <div id="success-message" class="hidden rounded-lg bg-green-50 border border-green-200 p-6 text-center">
        <svg class="mx-auto h-12 w-12 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <div class="mt-4 text-lg font-medium survey-rich-content" id="success-text">
            @if($hasThankYouPage && !empty($thankYouSettings['message']))
                {!! $thankYouSettings['message'] !!}
            @else
                {{ $survey->submit_success_message ?? '感謝您的填寫！' }}
            @endif
        </div>
        @if($hasThankYouPage && !empty($thankYouSettings['redirect_url']))
            <a class="mt-4 inline-flex rounded-md border border-green-300 px-4 py-2 text-sm font-medium text-green-800" href="{{ $thankYouSettings['redirect_url'] }}">繼續</a>
        @endif
    </div>

    {{-- Error banner --}}
    <div id="error-banner" class="hidden rounded-lg bg-red-50 border border-red-200 p-4 mb-6">
        <p class="text-sm text-red-700" id="error-text"></p>
    </div>

    @if($progressMode !== 'none' && $pageCount > 0)
    <div id="page-indicator" class="text-sm text-gray-500 text-center mb-4 @if($hasWelcomePage) hidden @endif">
        @if($progressMode === 'bar')
            <progress id="progress-bar" max="{{ $pageCount }}" value="1" class="h-2 w-full"></progress>
        @elseif($progressMode === 'steps')
            <div id="progress-steps" class="flex justify-center gap-2">
                @foreach(range(1, $pageCount) as $step)
                    <span class="progress-step inline-block h-2.5 w-2.5 rounded-full {{ $step === 1 ? 'bg-indigo-600' : 'bg-gray-300' }}"></span>
                @endforeach
            </div>
        @else
        第 <span id="current-page-label">1</span> 頁，共 {{ $pageCount }} 頁
            <span id="progress-percent">（{{ $pageCount > 0 ? (int) round(100 / $pageCount) : 0 }}%）</span>
        @endif
    </div>
    @endif

    {{-- Form --}}
    <form id="survey-form" class="space-y-6 @if($hasWelcomePage) hidden @endif" novalidate>
        @csrf
        <input type="text" name="_hp" autocomplete="off" tabindex="-1" aria-hidden="true" class="hidden" style="display:none">

        @foreach($renderPages as $rp)
        <div class="survey-page space-y-6 @if(!$loop->first) hidden @endif"
             data-page-key="{{ $rp['key'] }}">

            @foreach($rp['fields'] as $field)
            @php $fk = $field->field_key; $type = $field->type->value; @endphp

            @if($type === 'section_title')
            <div class="survey-field" data-field-key="{{ $fk }}">
                <h2 class="text-lg font-semibold text-gray-900">{{ $field->description }}</h2>
            </div>
            @elseif($type === 'description_block')
            <div class="survey-field" data-field-key="{{ $fk }}">
                <div class="text-sm text-gray-700 survey-rich-content">{!! $field->description !!}</div>
            </div>
            @else
            <div class="survey-field bg-white rounded-lg border border-gray-200 p-5 shadow-sm"
                 data-field-key="{{ $fk }}"
                 @if($field->show_if_field_key)
                 data-show-if-field="{{ $field->show_if_field_key }}"
                 data-show-if-value="{{ $field->show_if_value }}"
                 @endif>

                <label class="block text-sm font-medium text-gray-900 mb-1">
                    {{ $field->label }}
                    @if($field->is_required)<span class="text-red-500 ml-0.5">*</span>@endif
                </label>

                @if($field->description)
                    <p class="text-xs text-gray-500 mb-2">{{ $field->description }}</p>
                @endif

                @if($type === 'short_text' || $type === 'email' || $type === 'phone')
                    <input
                        type="{{ $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text') }}"
                        name="answers[{{ $fk }}]"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                    >

                @elseif($type === 'long_text')
                    <textarea
                        name="answers[{{ $fk }}]"
                        rows="4"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                    >{{ $field->default_value ?? '' }}</textarea>

                @elseif($type === 'single_choice')
                    <div class="space-y-2 mt-1" data-jump-field="{{ $fk }}">
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <label class="flex items-center gap-2 {{ $isFull ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $option['value'] }}"
                                    @if($field->is_required) required @endif
                                    @if($isFull) disabled @endif
                                    class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'multiple_choice')
                    <div class="space-y-2 mt-1">
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <label class="flex items-center gap-2 {{ $isFull ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                <input type="checkbox" name="answers[{{ $fk }}][]" value="{{ $option['value'] }}"
                                    @if($isFull) disabled @endif
                                    class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]"
                        @if($field->is_required) required @endif
                        data-jump-field="{{ $fk }}"
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        <option value="">請選擇</option>
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <option value="{{ $option['value'] }}" @if($field->default_value === $option['value']) selected @endif @if($isFull) disabled @endif>
                                {{ $option['label'] }}@if($isFull)（已額滿）@endif
                            </option>
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    @php
                        $ratingCount = (int)($field->settings_json['count'] ?? 5);
                        $ratingShape = $field->settings_json['shape'] ?? 'star';
                        $ratingIcons = ['star' => '★', 'heart' => '♥', 'check' => '✔', 'thumb' => '👍'];
                        $ratingIcon  = $ratingIcons[$ratingShape] ?? '★';
                        $ratingId    = 'rating_' . $fk;
                    @endphp
                    <div class="survey-rating-stars mt-1" data-rating-id="{{ $ratingId }}">
                        @foreach(range(1, $ratingCount) as $star)
                            <label class="survey-rating-star-label" title="{{ $star }} 分">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $star }}"
                                    @if($field->is_required) required @endif
                                    class="sr-only survey-rating-radio">
                                <span class="survey-rating-star-icon">{{ $ratingIcon }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'date')
                    <input type="date" name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                @elseif($type === 'number')
                    <div class="flex items-center gap-2">
                        <input type="number" name="answers[{{ $fk }}]"
                            value="{{ $field->default_value ?? '' }}"
                            min="{{ $field->settings_json['min'] ?? '' }}"
                            max="{{ $field->settings_json['max'] ?? '' }}"
                            step="{{ $field->settings_json['step'] ?? '1' }}"
                            @if($field->is_required) required @endif
                            class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        @if(!empty($field->settings_json['unit']))
                            <span class="text-sm text-gray-500">{{ $field->settings_json['unit'] }}</span>
                        @endif
                    </div>
                @elseif($type === 'nps')
                    @php
                        $npsColorBands = !empty($field->settings_json['color_bands']);
                        $npsLow  = $field->settings_json['low_label']  ?? '非常不推薦';
                        $npsHigh = $field->settings_json['high_label'] ?? '非常推薦';
                    @endphp
                    <div class="survey-nps-wrap">
                        <div class="survey-nps-row">
                            @foreach(range(0, 10) as $score)
                                @php
                                    $npsClass = '';
                                    if ($npsColorBands) {
                                        if ($score <= 6) $npsClass = 'red';
                                        elseif ($score <= 8) $npsClass = 'yellow';
                                        else $npsClass = 'green';
                                    }
                                @endphp
                                <label class="survey-nps-label">
                                    <input type="radio" name="answers[{{ $fk }}]" value="{{ $score }}" class="sr-only survey-nps-radio" @if($field->is_required) required @endif>
                                    <span class="survey-nps-pip {{ $npsClass }}">{{ $score }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="survey-nps-labels">
                            <span>{{ $npsLow }}</span>
                            <span>{{ $npsHigh }}</span>
                        </div>
                    </div>
                @elseif($type === 'matrix_single' || $type === 'matrix_multi')
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-md text-sm">
                            <thead>
                                <tr>
                                    <th></th>
                                    @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                        <th class="px-2 py-2 text-center font-medium text-gray-600">{{ $col['label'] ?? '' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($field->settings_json['matrix_rows'] ?? []) as $row)
                                    <tr>
                                        <th class="px-2 py-2 text-left font-medium text-gray-700">{{ $row['label'] ?? '' }}</th>
                                        @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                            <td class="px-2 py-2 text-center">
                                                <input
                                                    type="{{ $type === 'matrix_multi' ? 'checkbox' : 'radio' }}"
                                                    name="answers[{{ $fk }}][{{ $row['id'] ?? '' }}]{{ $type === 'matrix_multi' ? '[]' : '' }}"
                                                    value="{{ $col['id'] ?? '' }}"
                                                    @if($field->is_required && $type === 'matrix_single') required @endif
                                                    class="text-indigo-600 focus:ring-indigo-500">
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($type === 'ranking')
                    <div class="space-y-2">
                        @foreach($field->optionsForDisplay() as $optVal => $optLabel)
                            <label class="flex items-center gap-2">
                                <input type="number" min="1" max="{{ count($field->optionsForDisplay()) }}" data-ranking-field="{{ $fk }}" data-ranking-option="{{ $optVal }}" class="w-16 rounded-md border border-gray-300 px-2 py-1 text-sm">
                                <span class="text-sm text-gray-700">{{ $optLabel }}</span>
                            </label>
                        @endforeach
                        <input type="hidden" name="answers[{{ $fk }}]" data-ranking-value="{{ $fk }}">
                    </div>
                @elseif($type === 'file_upload')
                    <input type="file" data-file-upload-field="{{ $fk }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <input type="hidden" name="answers[{{ $fk }}][media_id]" data-file-media-id="{{ $fk }}">
                    <input type="hidden" name="answers[{{ $fk }}][filename]" data-file-filename="{{ $fk }}">
                    <input type="hidden" name="answers[{{ $fk }}][size]" data-file-size="{{ $fk }}">
                @elseif($type === 'signature')
                    <div class="space-y-2">
                        <canvas data-signature-canvas="{{ $fk }}" width="640" height="220" class="h-40 w-full rounded-md border border-gray-300 bg-white"></canvas>
                        <input type="hidden" name="answers[{{ $fk }}][data_url]" data-signature-value="{{ $fk }}" @if($field->is_required) required @endif>
                        <button type="button" data-signature-clear="{{ $fk }}" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-600">清除簽名</button>
                    </div>
                @elseif($type === 'address')
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach(($field->settings_json['fields_enabled'] ?? ['country','city','district','address','postal_code']) as $addressKey)
                            @if($addressKey === 'country' && !empty($field->settings_json['country_locked']))
                                <input type="hidden" name="answers[{{ $fk }}][country]" value="{{ $field->settings_json['country_locked'] }}">
                            @else
                                <input type="text" name="answers[{{ $fk }}][{{ $addressKey }}]" placeholder="{{ $addressKey }}" @if($field->is_required) required @endif class="rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                            @endif
                        @endforeach
                    </div>
                @endif

                <p class="text-xs text-red-500 mt-1 hidden field-error" data-field="{{ $fk }}"></p>
            </div>
            @endif
            @endforeach
        </div>
        @endforeach

        {{-- Terms checkbox --}}
        @if($hasTerms)
        <div id="terms-row" class="rounded-lg bg-white border border-gray-200 p-4 shadow-sm" style="background: var(--survey-surface); border-color: var(--survey-border); border-radius: var(--survey-radius);">
            <label class="flex items-start gap-3 cursor-pointer text-sm text-gray-700" style="color: var(--survey-text);">
                <input type="checkbox" id="terms-checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 accent-indigo-600" style="accent-color: var(--survey-primary);">
                <span>{{ $termsText }}</span>
            </label>
        </div>
        @endif

        {{-- Turnstile widget --}}
        @if($turnstileEnabled && $turnstiteSiteKey)
        <div class="cf-turnstile" data-sitekey="{{ $turnstiteSiteKey }}" data-callback="onTurnstileSuccess"></div>
        @endif

        {{-- Navigation --}}
        <div class="flex justify-between pt-2">
            <button type="button" id="btn-prev"
                class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-6 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 hidden">
                上一頁
            </button>
            <div id="nav-right" class="flex gap-2 ml-auto">
                @if($isMultiPage)
                <button type="button" id="btn-next"
                    class="survey-themed-primary inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90">
                    下一頁
                </button>
                @endif
                <button type="submit" id="submit-btn"
                    class="survey-themed-primary inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-60 @if($isMultiPage) hidden @endif"
                    @if($hasTerms) disabled @endif>
                    <span id="submit-label">送出問卷</span>
                    <svg id="submit-spinner" class="hidden animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </button>
            </div>
        </div>
    </form>
    @if($hasPassword)</div>@endif
</div>
</div>

@else

{{-- ======================================================= PUBLISHED CSS LAYOUT ===== --}}
<div class="survey-container">

    <div class="survey-header">
        <h1 class="survey-title">{{ $survey->title }}</h1>
        @if($survey->description)
            <p class="survey-description">{{ $survey->description }}</p>
        @endif
    </div>

    {{-- Password Gate (published mode) --}}
    @if($hasPassword)
    <div id="password-gate" class="survey-field-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <p class="survey-field-label" style="font-size:1rem;margin-bottom:4px;">此問卷設有密碼保護</p>
        <p class="survey-field-description" style="margin-bottom:12px;">請輸入密碼以繼續填寫</p>
        <div style="display:flex;gap:8px;align-items:center;">
            <input id="password-input" type="password" placeholder="輸入密碼" class="survey-input" style="max-width:220px;">
            <button type="button" id="btn-unlock" class="survey-btn survey-btn--primary" style="padding:0.5rem 1.25rem;">解鎖</button>
        </div>
        <p id="password-error" class="survey-field-error" style="display:none;margin-top:8px;">密碼不正確，請重試。</p>
    </div>
    <div id="after-gate" class="survey-hidden">
    @endif

    <div id="success-message" class="survey-banner survey-banner--success survey-hidden">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <p id="success-text">{{ $survey->submit_success_message ?? '感謝您的填寫！' }}</p>
    </div>

    <div id="error-banner" class="survey-banner survey-banner--error survey-hidden">
        <p id="error-text"></p>
    </div>

    @if($isMultiPage)
    <p id="page-indicator" class="survey-page-indicator">
        第 <span id="current-page-label">1</span> 頁，共 {{ $pageCount }} 頁
    </p>
    @endif

    <form id="survey-form" novalidate>
        <input type="text" name="_hp" autocomplete="off" tabindex="-1" aria-hidden="true" class="survey-hidden" style="display:none">

        @foreach($renderPages as $rp)
        <div class="survey-form-pages survey-page @if(!$loop->first) survey-hidden @endif"
             data-page-key="{{ $rp['key'] }}">

            @foreach($rp['fields'] as $field)
            @php $fk = $field->field_key; $type = $field->type->value; @endphp

            @if($type === 'section_title')
            <div class="survey-field" data-field-key="{{ $fk }}">
                <h2 class="survey-section-title">{{ $field->description }}</h2>
            </div>
            @elseif($type === 'description_block')
            <div class="survey-field" data-field-key="{{ $fk }}">
                <div class="survey-description-block survey-rich-content">{!! $field->description !!}</div>
            </div>
            @else
            <div class="survey-field survey-field-card"
                 data-field-key="{{ $fk }}"
                 @if($field->show_if_field_key)
                 data-show-if-field="{{ $field->show_if_field_key }}"
                 data-show-if-value="{{ $field->show_if_value }}"
                 @endif>

                <label class="survey-field-label">
                    {{ $field->label }}
                    @if($field->is_required)<span class="survey-field-required">*</span>@endif
                </label>

                @if($field->description)
                    <p class="survey-field-description">{{ $field->description }}</p>
                @endif

                @if($type === 'short_text' || $type === 'email' || $type === 'phone')
                    <input
                        type="{{ $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text') }}"
                        name="answers[{{ $fk }}]"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-input"
                    >

                @elseif($type === 'long_text')
                    <textarea name="answers[{{ $fk }}]" rows="4"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-textarea">{{ $field->default_value ?? '' }}</textarea>

                @elseif($type === 'single_choice')
                    <div class="survey-choices" data-jump-field="{{ $fk }}">
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <label class="survey-choice-label" @if($isFull) style="opacity:.6" @endif>
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $option['value'] }}"
                                    @if($field->is_required) required @endif
                                    @if($isFull) disabled @endif>
                                <span>{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'multiple_choice')
                    <div class="survey-choices">
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <label class="survey-choice-label" @if($isFull) style="opacity:.6" @endif>
                                <input type="checkbox" name="answers[{{ $fk }}][]" value="{{ $option['value'] }}" @if($isFull) disabled @endif>
                                <span>{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]"
                        @if($field->is_required) required @endif
                        data-jump-field="{{ $fk }}"
                        class="survey-select">
                        <option value="">請選擇</option>
                        @foreach($field->normalizedOptions() as $option)
                            @continue($option['is_hidden'])
                            @php
                                $used = $optionUsage[$fk][$option['value']] ?? 0;
                                $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                            @endphp
                            <option value="{{ $option['value'] }}" @if($field->default_value === $option['value']) selected @endif @if($isFull) disabled @endif>
                                {{ $option['label'] }}@if($isFull)（已額滿）@endif
                            </option>
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    @php
                        $ratingCount = (int)($field->settings_json['count'] ?? 5);
                        $ratingShape = $field->settings_json['shape'] ?? 'star';
                        $ratingIcons = ['star' => '★', 'heart' => '♥', 'check' => '✔', 'thumb' => '👍'];
                        $ratingIcon  = $ratingIcons[$ratingShape] ?? '★';
                        $ratingId    = 'rating_' . $fk;
                    @endphp
                    <div class="survey-rating-stars" data-rating-id="{{ $ratingId }}">
                        @foreach(range(1, $ratingCount) as $star)
                            <label class="survey-rating-star-label" title="{{ $star }} 分">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $star }}"
                                    @if($field->is_required) required @endif
                                    class="sr-only survey-rating-radio">
                                <span class="survey-rating-star-icon">{{ $ratingIcon }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'date')
                    <input type="date" name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-input">
                @elseif($type === 'number')
                    <input type="number" name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        min="{{ $field->settings_json['min'] ?? '' }}"
                        max="{{ $field->settings_json['max'] ?? '' }}"
                        step="{{ $field->settings_json['step'] ?? '1' }}"
                        @if($field->is_required) required @endif
                        class="survey-input">
                @elseif($type === 'nps')
                    @php
                        $npsColorBands = !empty($field->settings_json['color_bands']);
                        $npsLow  = $field->settings_json['low_label']  ?? '非常不推薦';
                        $npsHigh = $field->settings_json['high_label'] ?? '非常推薦';
                    @endphp
                    <div class="survey-nps-wrap">
                        <div class="survey-nps-row">
                            @foreach(range(0, 10) as $score)
                                @php
                                    $npsClass = '';
                                    if ($npsColorBands) {
                                        if ($score <= 6) $npsClass = 'red';
                                        elseif ($score <= 8) $npsClass = 'yellow';
                                        else $npsClass = 'green';
                                    }
                                @endphp
                                <label class="survey-nps-label">
                                    <input type="radio" name="answers[{{ $fk }}]" value="{{ $score }}" class="sr-only survey-nps-radio" @if($field->is_required) required @endif>
                                    <span class="survey-nps-pip {{ $npsClass }}">{{ $score }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="survey-nps-labels">
                            <span>{{ $npsLow }}</span>
                            <span>{{ $npsHigh }}</span>
                        </div>
                    </div>
                @elseif($type === 'matrix_single' || $type === 'matrix_multi')
                    <div style="overflow-x:auto">
                        <table class="survey-matrix">
                            <thead>
                                <tr>
                                    <th></th>
                                    @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                        <th>{{ $col['label'] ?? '' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($field->settings_json['matrix_rows'] ?? []) as $row)
                                    <tr>
                                        <th>{{ $row['label'] ?? '' }}</th>
                                        @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                            <td>
                                                <input
                                                    type="{{ $type === 'matrix_multi' ? 'checkbox' : 'radio' }}"
                                                    name="answers[{{ $fk }}][{{ $row['id'] ?? '' }}]{{ $type === 'matrix_multi' ? '[]' : '' }}"
                                                    value="{{ $col['id'] ?? '' }}"
                                                    @if($field->is_required && $type === 'matrix_single') required @endif>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($type === 'ranking')
                    <div class="survey-choices">
                        @foreach($field->optionsForDisplay() as $optVal => $optLabel)
                            <label class="survey-choice-label">
                                <input type="number" min="1" max="{{ count($field->optionsForDisplay()) }}" data-ranking-field="{{ $fk }}" data-ranking-option="{{ $optVal }}">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @endforeach
                        <input type="hidden" name="answers[{{ $fk }}]" data-ranking-value="{{ $fk }}">
                    </div>
                @elseif($type === 'file_upload')
                    <input type="file" data-file-upload-field="{{ $fk }}" class="survey-input">
                    <input type="hidden" name="answers[{{ $fk }}][media_id]" data-file-media-id="{{ $fk }}">
                    <input type="hidden" name="answers[{{ $fk }}][filename]" data-file-filename="{{ $fk }}">
                    <input type="hidden" name="answers[{{ $fk }}][size]" data-file-size="{{ $fk }}">
                @elseif($type === 'signature')
                    <div class="survey-choices">
                        <canvas data-signature-canvas="{{ $fk }}" width="640" height="220" class="survey-input" style="height: 10rem; background: #fff;"></canvas>
                        <input type="hidden" name="answers[{{ $fk }}][data_url]" data-signature-value="{{ $fk }}" @if($field->is_required) required @endif>
                        <button type="button" data-signature-clear="{{ $fk }}" class="survey-btn-secondary">清除簽名</button>
                    </div>
                @elseif($type === 'address')
                    <div class="survey-choices">
                        @foreach(($field->settings_json['fields_enabled'] ?? ['country','city','district','address','postal_code']) as $addressKey)
                            @if($addressKey === 'country' && !empty($field->settings_json['country_locked']))
                                <input type="hidden" name="answers[{{ $fk }}][country]" value="{{ $field->settings_json['country_locked'] }}">
                            @else
                                <input type="text" name="answers[{{ $fk }}][{{ $addressKey }}]" placeholder="{{ $addressKey }}" @if($field->is_required) required @endif class="survey-input">
                            @endif
                        @endforeach
                    </div>
                @endif

                <p class="survey-field-error field-error" data-field="{{ $fk }}"></p>
            </div>
            @endif
            @endforeach
        </div>
        @endforeach

        {{-- Terms checkbox (published mode) --}}
        @if($hasTerms)
        <div id="terms-row" class="survey-field-card" style="margin-bottom:1rem;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:0.875rem;color:#374151;">
                <input type="checkbox" id="terms-checkbox"
                    style="margin-top:2px;width:1rem;height:1rem;accent-color:var(--survey-primary);cursor:pointer;flex-shrink:0;">
                <span>{{ $termsText }}</span>
            </label>
        </div>
        @endif

        {{-- Turnstile widget (published mode) --}}
        @if($turnstileEnabled && $turnstiteSiteKey)
        <div class="cf-turnstile" data-sitekey="{{ $turnstiteSiteKey }}" data-callback="onTurnstileSuccess" style="margin-bottom:1rem;"></div>
        @endif

        <div class="survey-nav">
            <button type="button" id="btn-prev"
                class="survey-btn survey-btn--secondary survey-hidden">
                上一頁
            </button>
            <div id="nav-right" class="survey-nav-right">
                @if($isMultiPage)
                <button type="button" id="btn-next" class="survey-btn survey-btn--primary">下一頁</button>
                @endif
                <button type="submit" id="submit-btn"
                    class="survey-btn survey-btn--primary @if($isMultiPage) survey-hidden @endif"
                    @if($hasTerms) disabled @endif>
                    <span id="submit-label">送出問卷</span>
                    <svg id="submit-spinner" class="survey-spinner" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </button>
            </div>
        </div>
    </form>
    @if($hasPassword)</div>@endif
</div>

@endif

<script>
(function () {
    // ─── Config ──────────────────────────────────────────────────────────────
    var IS_MULTI_PAGE = {{ $isMultiPage ? 'true' : 'false' }};
    var PAGES_DATA    = @json($pagesData);   // [{id, title}, ...]
    var BRANCHING     = @json($branchingMap);
    var JUMP_MAP      = @json($jumpMap);     // {field_key: {value: {type, target_page_id?}}}
    var PAGE_JUMP_MAP = @json($pageJumpMap);
    var THANK_YOU_MESSAGE = @json($hasThankYouPage ? ($thankYouSettings['message'] ?? null) : null);
    var STARTED_AT = Date.now();

    // ─── Access controls ──────────────────────────────────────────────────────
    var SURVEY_PASSWORD = @json($surveyPassword);
    var HAS_TERMS = {{ $hasTerms ? 'true' : 'false' }};
    var ENABLE_RESPONSE_NO = {{ $enableResponseNo ? 'true' : 'false' }};
    var HAS_TURNSTILE = {{ ($turnstileEnabled && $turnstiteSiteKey) ? 'true' : 'false' }};
    var turnstileToken = null;

    // Turnstile callback (called by widget on success)
    window.onTurnstileSuccess = function (token) { turnstileToken = token; };

    // Password gate
    var passwordGate = document.getElementById('password-gate');
    var afterGate    = document.getElementById('after-gate');
    var btnUnlock    = document.getElementById('btn-unlock');
    var passwordInput = document.getElementById('password-input');
    var passwordError = document.getElementById('password-error');

    if (passwordGate && SURVEY_PASSWORD) {
        if (btnUnlock) {
            btnUnlock.addEventListener('click', function () {
                var val = passwordInput ? passwordInput.value : '';
                if (val === SURVEY_PASSWORD) {
                    passwordGate.style.display = 'none';
                    if (afterGate) afterGate.classList.remove('hidden', 'survey-hidden');
                    // Also remove inline display:none on afterGate
                    if (afterGate) afterGate.style.display = '';
                } else {
                    if (passwordError) {
                        passwordError.style.display = 'block';
                        passwordError.classList.remove('hidden');
                    }
                }
            });
        }
        if (passwordInput) {
            passwordInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { if (btnUnlock) btnUnlock.click(); }
            });
        }
    }

    // Terms checkbox
    var termsCheckbox = document.getElementById('terms-checkbox');
    var submitBtnRef  = document.getElementById('submit-btn');
    if (HAS_TERMS && termsCheckbox && submitBtnRef) {
        termsCheckbox.addEventListener('change', function () {
            submitBtnRef.disabled = !termsCheckbox.checked;
        });
    }

    // Generate response number (SR-YYYYMMDD-XXXXXX)
    function generateResponseNumber() {
        if (!ENABLE_RESPONSE_NO) return null;
        var now = new Date();
        var y = now.getFullYear();
        var m = String(now.getMonth() + 1).padStart(2, '0');
        var d = String(now.getDate()).padStart(2, '0');
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        var rand = '';
        for (var i = 0; i < 6; i++) rand += chars[Math.floor(Math.random() * chars.length)];
        return 'SR-' + y + m + d + '-' + rand;
    }
    var RESPONSE_NUMBER = generateResponseNumber();

    // ─── History stack ────────────────────────────────────────────────────────
    var pageStack      = [];  // visited page keys (not including current)
    var currentPageKey = PAGES_DATA.length > 0 ? PAGES_DATA[0].id : null;

    // ─── Helpers ──────────────────────────────────────────────────────────────
    function isCdnMode() {
        return document.querySelector('script[src*="tailwindcss"]') !== null;
    }

    function hiddenClass() { return isCdnMode() ? 'hidden' : 'survey-hidden'; }
    function hide(el) { if (el) el.classList.add(hiddenClass()); }
    function show(el) { if (el) el.classList.remove(hiddenClass()); }

    function getAnswerValue(fieldKey) {
        var radio = document.querySelector('input[name="answers[' + fieldKey + ']"]:checked');
        if (radio) { return radio.value; }

        var checkboxes = document.querySelectorAll('input[name="answers[' + fieldKey + '][]"]:checked');
        if (checkboxes.length > 0) {
            return Array.from(checkboxes).map(function (cb) { return cb.value; });
        }

        var inp = document.querySelector('[name="answers[' + fieldKey + ']"]');
        return inp ? inp.value : null;
    }

    // ─── Jump logic ───────────────────────────────────────────────────────────
    // Returns: page_key | 'END_SURVEY' | null (no next page)
    function resolveNextPageKey(fromPageKey) {
        var fromIdx = PAGES_DATA.findIndex(function (p) { return p.id === fromPageKey; });
        var pageEl  = document.querySelector('[data-page-key="' + fromPageKey + '"]');

        if (!pageEl) { return null; }

        // Find first jump-configured field on the current page
        var jumpFieldEl = pageEl.querySelector('[data-jump-field]');
        var jumpFieldKey = jumpFieldEl ? jumpFieldEl.getAttribute('data-jump-field') : null;

        var nextKey = fromIdx + 1 < PAGES_DATA.length ? PAGES_DATA[fromIdx + 1].id : null;

        if (jumpFieldKey && JUMP_MAP[jumpFieldKey]) {
            var answer    = getAnswerValue(jumpFieldKey);
            var actionMap = JUMP_MAP[jumpFieldKey];
            var action    = (answer !== null && answer !== '') ? actionMap[answer] : null;

            if (action && action.type === 'end_survey') { return 'END_SURVEY'; }

            if (action && action.type === 'go_to_page') { nextKey = action.target_page_id || null; }
        }

        var pageRules = PAGE_JUMP_MAP[fromPageKey] || [];
        for (var i = 0; i < pageRules.length; i += 1) {
            var rule = pageRules[i];
            if (!conditionGroupPasses(rule.condition || {})) { continue; }

            if (rule.action && rule.action.type === 'end_survey') { return 'END_SURVEY'; }
            if (rule.action && rule.action.type === 'go_to_page') { return rule.action.target_page_id || null; }
        }

        return nextKey;
    }

    // ─── Page display ─────────────────────────────────────────────────────────
    function updateNavButtons() {
        var prevBtn   = document.getElementById('btn-prev');
        var nextBtn   = document.getElementById('btn-next');
        var submitBtn = document.getElementById('submit-btn');
        var navRight  = document.getElementById('nav-right');

        // Show prev when there's history
        if (prevBtn) {
            if (pageStack.length > 0) { show(prevBtn); } else { hide(prevBtn); }
        }

        // Update nav-right alignment
        if (navRight) {
            if (pageStack.length > 0) {
                navRight.style.marginLeft = '';
            } else {
                navRight.style.marginLeft = 'auto';
            }
        }

        if (!nextBtn || !submitBtn) { return; }

        var nextKey = resolveNextPageKey(currentPageKey);

        if (nextKey === null || nextKey === 'END_SURVEY') {
            // Last page or end_survey → show submit
            hide(nextBtn);
            show(submitBtn);
        } else {
            show(nextBtn);
            hide(submitBtn);
        }
    }

    function showPage(pageKey) {
        document.querySelectorAll('[data-page-key]').forEach(function (el) {
            if (el.getAttribute('data-page-key') === pageKey) {
                el.classList.remove(hiddenClass());
            } else {
                el.classList.add(hiddenClass());
            }
        });

        currentPageKey = pageKey;

        // Page indicator (position in PAGES_DATA order)
        var pageIdx  = PAGES_DATA.findIndex(function (p) { return p.id === pageKey; });
        var indicator = document.getElementById('current-page-label');
        if (indicator) { indicator.textContent = pageIdx + 1; }
        var progressBar = document.getElementById('progress-bar');
        if (progressBar) { progressBar.value = pageIdx + 1; }
        var progressPercent = document.getElementById('progress-percent');
        if (progressPercent && PAGES_DATA.length > 0) {
            progressPercent.textContent = '（' + Math.round(((pageIdx + 1) / PAGES_DATA.length) * 100) + '%）';
        }
        document.querySelectorAll('.progress-step').forEach(function (step, index) {
            if (index <= pageIdx) {
                step.classList.remove('bg-gray-300');
                step.classList.add('bg-indigo-600');
            } else {
                step.classList.remove('bg-indigo-600');
                step.classList.add('bg-gray-300');
            }
        });

        updateNavButtons();
        evaluateBranching();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ─── Validation ───────────────────────────────────────────────────────────
    function validatePage(pageKey) {
        var pageEl = document.querySelector('[data-page-key="' + pageKey + '"]');
        if (!pageEl) { return true; }

        var valid = true;
        pageEl.querySelectorAll(
            'input[required]:not(:disabled), textarea[required]:not(:disabled), select[required]:not(:disabled)'
        ).forEach(function (inp) {
            if (!inp.checkValidity()) {
                inp.reportValidity();
                valid = false;
            }
        });
        return valid;
    }

    // ─── Branching (show_if) ──────────────────────────────────────────────────
    function valueMatches(current, expected) {
        if (Array.isArray(current)) { return current.includes(expected); }
        return current === expected;
    }

    function conditionPasses(condition) {
        var current = getAnswerValue(condition.field_key || '');
        var expected = condition.value;
        var op = condition.op || 'equals';

        if (op === 'not_equals') { return !valueMatches(current, expected); }
        if (op === 'contains') { return valueMatches(current, expected) || String(current || '').includes(String(expected || '')); }
        if (op === 'not_contains') { return !(valueMatches(current, expected) || String(current || '').includes(String(expected || ''))); }
        if (op === 'greater_than') { return Number(current) > Number(expected); }
        if (op === 'less_than') { return Number(current) < Number(expected); }
        if (op === 'between') {
            var min = Array.isArray(expected) ? expected[0] : expected?.min;
            var max = Array.isArray(expected) ? expected[1] : expected?.max;
            return Number(current) >= Number(min) && Number(current) <= Number(max);
        }
        if (op === 'is_empty') { return current === null || current === '' || (Array.isArray(current) && current.length === 0); }
        if (op === 'is_not_empty') { return !(current === null || current === '' || (Array.isArray(current) && current.length === 0)); }

        return valueMatches(current, expected);
    }

    function conditionGroupPasses(group) {
        var conditions = Array.isArray(group.conditions) ? group.conditions : [];
        if (conditions.length === 0) { return true; }
        if ((group.logic || 'and') === 'or') {
            return conditions.some(conditionPasses);
        }
        return conditions.every(conditionPasses);
    }

    function disableInputs(container, disabled) {
        container.querySelectorAll('input, textarea, select').forEach(function (inp) {
            inp.disabled = disabled;
            if (disabled) {
                if (inp.type === 'checkbox' || inp.type === 'radio') { inp.checked = false; }
                else { inp.value = ''; }
            }
        });
    }

    function evaluateBranching() {
        Object.keys(BRANCHING).forEach(function (fieldKey) {
            var rule      = BRANCHING[fieldKey];
            var container = document.querySelector('[data-field-key="' + fieldKey + '"]');
            if (!container) { return; }

            var visible = conditionGroupPasses(rule);

            if (visible) {
                show(container);
                disableInputs(container, false);
            } else {
                hide(container);
                disableInputs(container, true);
            }
        });
    }

    // ─── Error display ────────────────────────────────────────────────────────
    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(function (el) {
            el.textContent = '';
            if (isCdnMode()) { el.classList.add('hidden'); }
            else { el.classList.remove('visible'); }
        });
        var banner = document.getElementById('error-banner');
        if (banner) { hide(banner); }
    }

    function showFieldErrors(errors) {
        Object.entries(errors).forEach(function (entry) {
            var field    = entry[0];
            var messages = entry[1];
            var el = document.querySelector('.field-error[data-field="' + field + '"]');
            if (!el) { return; }
            el.textContent = Array.isArray(messages) ? messages[0] : messages;
            if (isCdnMode()) { el.classList.remove('hidden'); }
            else { el.classList.add('visible'); }
        });
    }

    // ─── Submit ───────────────────────────────────────────────────────────────
    function collectAnswers() {
        var formData = new FormData(document.getElementById('survey-form'));
        var answers  = {};
        for (var pair of formData.entries()) {
            var match = pair[0].match(/^answers\[([^\]]+)\](?:\[([^\]]+)\])?(\[\])?$/);
            if (!match) { continue; }
            var fieldKey = match[1];
            var childKey = match[2] || null;
            var isArray  = !!match[3];

            if (childKey) {
                if (!answers[fieldKey] || Array.isArray(answers[fieldKey])) { answers[fieldKey] = {}; }
                if (isArray) {
                    if (!answers[fieldKey][childKey]) { answers[fieldKey][childKey] = []; }
                    answers[fieldKey][childKey].push(pair[1]);
                } else {
                    answers[fieldKey][childKey] = pair[1];
                }
            } else if (isArray) {
                if (!answers[fieldKey]) { answers[fieldKey] = []; }
                answers[fieldKey].push(pair[1]);
            } else if (document.querySelector('[data-ranking-value="' + fieldKey + '"]')) {
                answers[fieldKey] = pair[1] ? pair[1].split(',').filter(Boolean) : [];
            } else {
                answers[fieldKey] = pair[1];
            }
        }
        return answers;
    }

    function updateRankingValues() {
        var grouped = {};
        document.querySelectorAll('[data-ranking-field]').forEach(function (input) {
            var fieldKey = input.getAttribute('data-ranking-field');
            if (!grouped[fieldKey]) { grouped[fieldKey] = []; }
            var rank = parseInt(input.value || '0', 10);
            if (rank > 0) {
                grouped[fieldKey].push({ rank: rank, option: input.getAttribute('data-ranking-option') });
            }
        });

        Object.keys(grouped).forEach(function (fieldKey) {
            var target = document.querySelector('[data-ranking-value="' + fieldKey + '"]');
            if (!target) { return; }
            target.value = grouped[fieldKey]
                .sort(function (a, b) { return a.rank - b.rank; })
                .map(function (entry) { return entry.option; })
                .join(',');
        });
    }

    async function updateFileUploadMeta(input) {
        var fieldKey = input.getAttribute('data-file-upload-field');
        var file = input.files && input.files[0] ? input.files[0] : null;
        if (!fieldKey || !file) { return; }
        var mediaId = document.querySelector('[data-file-media-id="' + fieldKey + '"]');
        var filename = document.querySelector('[data-file-filename="' + fieldKey + '"]');
        var size = document.querySelector('[data-file-size="' + fieldKey + '"]');
        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var body = new FormData();
        body.append('field_key', fieldKey);
        body.append('file', file);

        var res = await fetch('{{ route("survey.upload", $survey->public_key) }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: body,
        });
        var data = await res.json();

        if (!res.ok) {
            if (mediaId) { mediaId.value = ''; }
            if (filename) { filename.value = ''; }
            if (size) { size.value = ''; }
            showFieldErrors({ [fieldKey]: [data.message || '檔案上傳失敗。'] });
            return;
        }

        if (mediaId) { mediaId.value = String(data.media_id); }
        if (filename) { filename.value = data.filename || file.name; }
        if (size) { size.value = String(data.size || file.size); }
    }

    function initSignaturePads() {
        document.querySelectorAll('[data-signature-canvas]').forEach(function (canvas) {
            var fieldKey = canvas.getAttribute('data-signature-canvas');
            var target = document.querySelector('[data-signature-value="' + fieldKey + '"]');
            var context = canvas.getContext('2d');
            var drawing = false;

            if (!context || !target) { return; }

            context.lineWidth = 2;
            context.lineCap = 'round';
            context.strokeStyle = '#111827';

            function point(event) {
                var rect = canvas.getBoundingClientRect();
                var source = event.touches && event.touches[0] ? event.touches[0] : event;
                return {
                    x: (source.clientX - rect.left) * (canvas.width / rect.width),
                    y: (source.clientY - rect.top) * (canvas.height / rect.height),
                };
            }

            function persist() {
                target.value = canvas.toDataURL('image/png');
            }

            function start(event) {
                drawing = true;
                var p = point(event);
                context.beginPath();
                context.moveTo(p.x, p.y);
                event.preventDefault();
            }

            function move(event) {
                if (!drawing) { return; }
                var p = point(event);
                context.lineTo(p.x, p.y);
                context.stroke();
                persist();
                event.preventDefault();
            }

            function stop() {
                drawing = false;
            }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', stop);
            canvas.addEventListener('mouseleave', stop);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', stop);

            var clear = document.querySelector('[data-signature-clear="' + fieldKey + '"]');
            if (clear) {
                clear.addEventListener('click', function () {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    target.value = '';
                });
            }
        });
    }

    async function doSubmit() {
        clearErrors();

        var submitBtn = document.getElementById('submit-btn');
        var spinner   = document.getElementById('submit-spinner');
        var label     = document.getElementById('submit-label');

        if (HAS_TURNSTILE && !turnstileToken) {
            var errorBanner = document.getElementById('error-banner');
            var errorText   = document.getElementById('error-text');
            if (errorText) { errorText.textContent = '請完成人機驗證後再送出。'; }
            if (errorBanner) { show(errorBanner); }
            return;
        }

        if (submitBtn) { submitBtn.disabled = true; }
        if (spinner) { spinner.style.display = 'inline-block'; }
        if (label) { label.textContent = '送出中…'; }

        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var url   = '{{ route("survey.submit", $survey->public_key) }}'
            + '{{ request()->has("t") ? "?t=" . request()->query("t") : "" }}';

        try {
            var res  = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    answers: collectAnswers(),
                    _elapsed_ms: Date.now() - STARTED_AT,
                    _hp: (document.querySelector('[name="_hp"]') || {}).value || '',
                    _response_number: RESPONSE_NUMBER,
                    _turnstile_token: turnstileToken,
                }),
            });

            var data = await res.json();

            if (res.ok) {
                hide(document.getElementById('survey-form'));
                show(document.getElementById('success-message'));
                var successText = document.getElementById('success-text');
                if (successText) {
                    var msg = data.message || THANK_YOU_MESSAGE || successText.innerHTML;
                    if (RESPONSE_NUMBER) { msg = msg.replace(/\{\{response_number\}\}/g, RESPONSE_NUMBER); }
                    successText.innerHTML = msg;
                }
            } else if (res.status === 422 && data.errors) {
                showFieldErrors(data.errors);
                if (submitBtn) { submitBtn.disabled = false; }
                if (spinner) { spinner.style.display = 'none'; }
                if (label) { label.textContent = '送出問卷'; }
            } else {
                var errorBanner = document.getElementById('error-banner');
                var errorText   = document.getElementById('error-text');
                if (errorText) { errorText.textContent = data.message ?? '送出失敗，請稍後再試。'; }
                if (errorBanner) { show(errorBanner); }
                if (submitBtn) { submitBtn.disabled = false; }
                if (spinner) { spinner.style.display = 'none'; }
                if (label) { label.textContent = '送出問卷'; }
            }
        } catch {
            var errorBanner = document.getElementById('error-banner');
            var errorText   = document.getElementById('error-text');
            if (errorText) { errorText.textContent = '網路錯誤，請稍後再試。'; }
            if (errorBanner) { show(errorBanner); }
            if (submitBtn) { submitBtn.disabled = false; }
            if (spinner) { spinner.style.display = 'none'; }
            if (label) { label.textContent = '送出問卷'; }
        }
    }

    // ─── Event wiring ─────────────────────────────────────────────────────────
    initSignaturePads();

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('[data-ranking-field]')) { updateRankingValues(); }
        if (event.target && event.target.matches('[data-file-upload-field]')) { void updateFileUploadMeta(event.target); }
        evaluateBranching();
        if (IS_MULTI_PAGE) { updateNavButtons(); }
    });
    document.addEventListener('input', evaluateBranching);

    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (pageStack.length > 0) {
                currentPageKey = pageStack.pop();
                showPage(currentPageKey);
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (!validatePage(currentPageKey)) { return; }

            var nextKey = resolveNextPageKey(currentPageKey);

            if (nextKey === 'END_SURVEY') {
                doSubmit();
                return;
            }

            if (nextKey !== null) {
                pageStack.push(currentPageKey);
                showPage(nextKey);
            }
        });
    }

    var startBtn = document.getElementById('btn-start');
    if (startBtn) {
        startBtn.addEventListener('click', function () {
            hide(document.getElementById('welcome-screen'));
            show(document.getElementById('survey-form'));
            show(document.getElementById('page-indicator'));
            if (currentPageKey) { showPage(currentPageKey); }
        });
    }

    document.getElementById('survey-form').addEventListener('submit', function (e) {
        e.preventDefault();
        doSubmit();
    });

    // ─── Rating stars interaction ──────────────────────────────────────────────
    document.querySelectorAll('.survey-rating-stars').forEach(function (wrap) {
        var labels = wrap.querySelectorAll('.survey-rating-star-label');

        function updateFill(upTo) {
            labels.forEach(function (lbl, idx) {
                lbl.classList.toggle('filled', idx < upTo);
            });
        }

        function getCheckedIndex() {
            var checked = wrap.querySelector('.survey-rating-radio:checked');
            if (!checked) return 0;
            return parseInt(checked.value, 10);
        }

        labels.forEach(function (lbl, idx) {
            lbl.addEventListener('mouseenter', function () {
                labels.forEach(function (l, i) {
                    l.classList.toggle('hovered', i <= idx);
                });
                updateFill(idx + 1);
            });

            lbl.addEventListener('mouseleave', function () {
                labels.forEach(function (l) { l.classList.remove('hovered'); });
                updateFill(getCheckedIndex());
            });

            lbl.querySelector('.survey-rating-radio').addEventListener('change', function () {
                updateFill(getCheckedIndex());
            });
        });

        // Restore state on page show (multi-page)
        updateFill(getCheckedIndex());
    });

    // ─── Init ─────────────────────────────────────────────────────────────────
    evaluateBranching();
    if (IS_MULTI_PAGE && currentPageKey) { showPage(currentPageKey); }
}());
</script>
</body>
</html>
