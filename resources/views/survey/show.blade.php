<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $survey->title }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $cssMode = config('survey-core.frontend.css', 'cdn');
    @endphp

    @if($cssMode === 'cdn')
        <script src="https://cdn.tailwindcss.com"></script>
    @elseif($cssMode === 'published')
        <link rel="stylesheet" href="{{ asset('vendor/survey-core/survey.css') }}">
    @else
        <link rel="stylesheet" href="{{ $cssMode }}">
    @endif
</head>
<body>

@php
    // Group visible fields by page (1-based), preserving sort_order within each page
    $allFields = $survey->fields->where('is_hidden', false)->sortBy('sort_order');
    $pages = $allFields->groupBy(fn ($f) => $f->page ?? 1)->sortKeys();
    $maxPage = $pages->keys()->max() ?? 1;
    $isMultiPage = $maxPage > 1;

    // Build branching map for JS: field_key → {fieldKey, value}
    $branchingMap = $allFields
        ->filter(fn ($f) => $f->show_if_field_key)
        ->mapWithKeys(fn ($f) => [$f->field_key => ['field' => $f->show_if_field_key, 'value' => $f->show_if_value]])
        ->toArray();
@endphp

{{-- ── CDN mode uses Tailwind utilities; published mode uses survey.css classes ── --}}
@if($cssMode === 'cdn')

{{-- ======================================================= TAILWIND CDN LAYOUT ===== --}}
<div class="bg-gray-50 min-h-screen py-10">
<div class="max-w-2xl mx-auto px-4">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">{{ $survey->title }}</h1>
        @if($survey->description)
            <p class="mt-2 text-gray-600 whitespace-pre-line">{{ $survey->description }}</p>
        @endif
    </div>

    {{-- Success --}}
    <div id="success-message" class="hidden rounded-lg bg-green-50 border border-green-200 p-6 text-center">
        <svg class="mx-auto h-12 w-12 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="mt-4 text-lg font-medium text-green-800" id="success-text">
            {{ $survey->submit_success_message ?? '感謝您的填寫！' }}
        </p>
    </div>

    {{-- Error banner --}}
    <div id="error-banner" class="hidden rounded-lg bg-red-50 border border-red-200 p-4 mb-6">
        <p class="text-sm text-red-700" id="error-text"></p>
    </div>

    @if($isMultiPage)
    <p id="page-indicator" class="text-sm text-gray-500 text-center mb-4">
        第 <span id="current-page-label">1</span> 頁，共 {{ $maxPage }} 頁
    </p>
    @endif

    {{-- Form --}}
    <form id="survey-form" class="space-y-6" novalidate>
        @csrf

        @foreach($pages as $pageNum => $fields)
        <div class="survey-page space-y-6 @if(!$loop->first) hidden @endif" data-page="{{ $pageNum }}">
            @foreach($fields as $field)
            @php $fk = $field->field_key; $type = $field->type->value; @endphp
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
                    <div class="space-y-2 mt-1">
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $optVal }}"
                                    @if($field->is_required) required @endif
                                    class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'multiple_choice')
                    <div class="space-y-2 mt-1">
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="answers[{{ $fk }}][]" value="{{ $optVal }}"
                                    class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        <option value="">請選擇</option>
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <option value="{{ $optVal }}" @if($field->default_value === $optVal) selected @endif>
                                {{ $optLabel }}
                            </option>
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    <div class="flex gap-3 mt-1 flex-wrap">
                        @foreach(range(1, 5) as $star)
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $star }}"
                                    @if($field->is_required) required @endif
                                    class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $star }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'date')
                    <input type="date" name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                @endif

                <p class="text-xs text-red-500 mt-1 hidden field-error" data-field="{{ $fk }}"></p>
            </div>
            @endforeach
        </div>
        @endforeach

        {{-- Navigation --}}
        <div class="flex justify-between pt-2">
            <button type="button" id="btn-prev"
                class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-6 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 @if(!$isMultiPage) hidden @endif">
                上一頁
            </button>
            <div class="flex gap-2 @if(!$isMultiPage) ml-auto @endif">
                @if($isMultiPage)
                <button type="button" id="btn-next"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    下一頁
                </button>
                @endif
                <button type="submit" id="submit-btn"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60 @if($isMultiPage) hidden @endif">
                    <span id="submit-label">送出問卷</span>
                    <svg id="submit-spinner" class="hidden animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </button>
            </div>
        </div>
    </form>
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
        第 <span id="current-page-label">1</span> 頁，共 {{ $maxPage }} 頁
    </p>
    @endif

    <form id="survey-form" novalidate>

        @foreach($pages as $pageNum => $fields)
        <div class="survey-form-pages survey-page @if(!$loop->first) survey-hidden @endif" data-page="{{ $pageNum }}">
            @foreach($fields as $field)
            @php $fk = $field->field_key; $type = $field->type->value; @endphp
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
                    <div class="survey-choices">
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <label class="survey-choice-label">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $optVal }}"
                                    @if($field->is_required) required @endif>
                                <span>{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'multiple_choice')
                    <div class="survey-choices">
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <label class="survey-choice-label">
                                <input type="checkbox" name="answers[{{ $fk }}][]" value="{{ $optVal }}">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]"
                        @if($field->is_required) required @endif
                        class="survey-select">
                        <option value="">請選擇</option>
                        @foreach($field->options_json ?? [] as $optVal => $optLabel)
                            <option value="{{ $optVal }}" @if($field->default_value === $optVal) selected @endif>
                                {{ $optLabel }}
                            </option>
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    <div class="survey-rating">
                        @foreach(range(1, 5) as $star)
                            <label class="survey-choice-label">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $star }}"
                                    @if($field->is_required) required @endif>
                                <span>{{ $star }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'date')
                    <input type="date" name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-input">
                @endif

                <p class="survey-field-error field-error" data-field="{{ $fk }}"></p>
            </div>
            @endforeach
        </div>
        @endforeach

        <div class="survey-nav">
            <button type="button" id="btn-prev"
                class="survey-btn survey-btn--secondary @if(!$isMultiPage) survey-hidden @endif">
                上一頁
            </button>
            <div class="survey-nav-right">
                @if($isMultiPage)
                <button type="button" id="btn-next" class="survey-btn survey-btn--primary">下一頁</button>
                @endif
                <button type="submit" id="submit-btn"
                    class="survey-btn survey-btn--primary @if($isMultiPage) survey-hidden @endif">
                    <span id="submit-label">送出問卷</span>
                    <svg id="submit-spinner" class="survey-spinner" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </button>
            </div>
        </div>
    </form>
</div>

@endif

<script>
(function () {
    // ─── Config ──────────────────────────────────────────────────────────
    var IS_MULTI_PAGE = {{ $isMultiPage ? 'true' : 'false' }};
    var MAX_PAGE = {{ $maxPage }};
    var BRANCHING = @json($branchingMap);
    var currentPage = 1;

    // ─── Helpers ─────────────────────────────────────────────────────────
    function isCdnMode() {
        return document.querySelector('script[src*="tailwindcss"]') !== null;
    }

    function hiddenClass() { return isCdnMode() ? 'hidden' : 'survey-hidden'; }

    function hide(el) { if (el) el.classList.add(hiddenClass()); }
    function show(el) { if (el) el.classList.remove(hiddenClass()); }

    function getFieldContainer(fieldKey) {
        return document.querySelector('[data-field-key="' + fieldKey + '"]');
    }

    // ─── Branching ───────────────────────────────────────────────────────
    function getAnswerValue(fieldKey) {
        var radio = document.querySelector('input[name="answers[' + fieldKey + ']"]:checked');
        if (radio) return radio.value;

        var checkboxes = document.querySelectorAll('input[name="answers[' + fieldKey + '][]"]:checked');
        if (checkboxes.length > 0) {
            return Array.from(checkboxes).map(function (cb) { return cb.value; });
        }

        var inp = document.querySelector('[name="answers[' + fieldKey + ']"]');
        return inp ? inp.value : null;
    }

    function valueMatches(current, expected) {
        if (Array.isArray(current)) return current.includes(expected);
        return current === expected;
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
            var rule = BRANCHING[fieldKey];
            var container = getFieldContainer(fieldKey);
            if (!container) return;

            var current = getAnswerValue(rule.field);
            var visible = valueMatches(current, rule.value);

            if (visible) {
                show(container);
                disableInputs(container, false);
            } else {
                hide(container);
                disableInputs(container, true);
            }
        });
    }

    // ─── Multi-page ──────────────────────────────────────────────────────
    function showPage(page) {
        document.querySelectorAll('.survey-page').forEach(function (el) {
            var elPage = parseInt(el.dataset.page);
            if (elPage === page) { el.classList.remove(hiddenClass()); }
            else { el.classList.add(hiddenClass()); }
        });

        var prevBtn = document.getElementById('btn-prev');
        var nextBtn = document.getElementById('btn-next');
        var submitBtn = document.getElementById('submit-btn');
        var indicator = document.getElementById('current-page-label');

        if (indicator) indicator.textContent = page;
        if (prevBtn) {
            if (page === 1) { hide(prevBtn); } else { show(prevBtn); }
        }
        if (nextBtn && submitBtn) {
            if (page === MAX_PAGE) { hide(nextBtn); show(submitBtn); }
            else { show(nextBtn); hide(submitBtn); }
        }

        currentPage = page;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function validatePage(page) {
        var pageEl = document.querySelector('.survey-page[data-page="' + page + '"]');
        if (!pageEl) return true;

        var valid = true;
        // Check required inputs that are not disabled (branching may have disabled them)
        pageEl.querySelectorAll('input[required]:not(:disabled), textarea[required]:not(:disabled), select[required]:not(:disabled)').forEach(function (inp) {
            if (!inp.checkValidity()) {
                inp.reportValidity();
                valid = false;
            }
        });
        return valid;
    }

    // ─── Error display ───────────────────────────────────────────────────
    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(function (el) {
            el.textContent = '';
            if (isCdnMode()) { el.classList.add('hidden'); }
            else { el.classList.remove('visible'); }
        });
        var banner = document.getElementById('error-banner');
        if (banner) hide(banner);
    }

    function showFieldErrors(errors) {
        Object.entries(errors).forEach(function (entry) {
            var field = entry[0], messages = entry[1];
            var el = document.querySelector('.field-error[data-field="' + field + '"]');
            if (!el) return;
            el.textContent = Array.isArray(messages) ? messages[0] : messages;
            if (isCdnMode()) { el.classList.remove('hidden'); }
            else { el.classList.add('visible'); }
        });
    }

    // ─── Submit ──────────────────────────────────────────────────────────
    function collectAnswers() {
        var formData = new FormData(document.getElementById('survey-form'));
        var answers = {};
        for (var pair of formData.entries()) {
            var match = pair[0].match(/^answers\[([^\]]+)\](\[\])?$/);
            if (!match) continue;
            var fieldKey = match[1];
            var isArray = !!match[2];
            if (isArray) {
                if (!answers[fieldKey]) answers[fieldKey] = [];
                answers[fieldKey].push(pair[1]);
            } else {
                answers[fieldKey] = pair[1];
            }
        }
        return answers;
    }

    // ─── Event wiring ─────────────────────────────────────────────────────
    document.addEventListener('change', evaluateBranching);
    document.addEventListener('input', evaluateBranching);

    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (currentPage > 1) showPage(currentPage - 1);
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (validatePage(currentPage) && currentPage < MAX_PAGE) {
                showPage(currentPage + 1);
            }
        });
    }

    document.getElementById('survey-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        clearErrors();

        var submitBtn = document.getElementById('submit-btn');
        var spinner = document.getElementById('submit-spinner');
        var label = document.getElementById('submit-label');

        submitBtn.disabled = true;
        if (spinner) { spinner.style.display = 'inline-block'; }
        if (label) label.textContent = '送出中…';

        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var url = '{{ route("survey.submit", $survey->public_key) }}'
            + '{{ request()->has("t") ? "?t=" . request()->query("t") : "" }}';

        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ answers: collectAnswers() }),
            });

            var data = await res.json();

            if (res.ok) {
                hide(document.getElementById('survey-form'));
                show(document.getElementById('success-message'));
                var successText = document.getElementById('success-text');
                if (successText && data.message) successText.textContent = data.message;
            } else if (res.status === 422 && data.errors) {
                showFieldErrors(data.errors);
                submitBtn.disabled = false;
                if (spinner) { spinner.style.display = 'none'; }
                if (label) label.textContent = '送出問卷';
            } else {
                var errorBanner = document.getElementById('error-banner');
                var errorText = document.getElementById('error-text');
                if (errorText) errorText.textContent = data.message ?? '送出失敗，請稍後再試。';
                if (errorBanner) show(errorBanner);
                submitBtn.disabled = false;
                if (spinner) { spinner.style.display = 'none'; }
                if (label) label.textContent = '送出問卷';
            }
        } catch {
            var errorBanner = document.getElementById('error-banner');
            var errorText = document.getElementById('error-text');
            if (errorText) errorText.textContent = '網路錯誤，請稍後再試。';
            if (errorBanner) show(errorBanner);
            submitBtn.disabled = false;
            if (spinner) { spinner.style.display = 'none'; }
            if (label) label.textContent = '送出問卷';
        }
    });

    // Run branching on load to apply initial conditions
    evaluateBranching();
    if (IS_MULTI_PAGE) showPage(1);
}());
</script>
</body>
</html>
