
{{-- ======================================================= TAILWIND CDN LAYOUT ===== --}}
<div class="bg-gray-50 min-h-screen py-10" style="background: var(--survey-background); color: var(--survey-text);">
<div id="main-content" tabindex="-1" class="max-w-2xl mx-auto px-4">

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
                aria-label="問卷密碼" aria-describedby="password-error"
                class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                style="border-color: var(--survey-border);">
            <button type="button" id="btn-unlock" class="survey-themed-primary inline-flex items-center rounded-md bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:opacity-90">
                解鎖
            </button>
        </div>
        <p id="password-error" role="alert" aria-live="assertive" class="hidden mt-2 text-sm text-red-600">密碼不正確，請重試。</p>
    </div>
    <div id="after-gate" class="hidden">
    @endif

    {{-- Welcome --}}
    @if($hasWelcomePage && $welcomePage)
    <div id="welcome-screen" class="rounded-lg bg-white border border-gray-200 p-8 text-center shadow-sm" style="background: var(--survey-surface); border-color: var(--survey-border); border-radius: var(--survey-radius);">
        @if(!empty($welcomeSettings['content']))
            <div class="mt-4 text-left survey-rich-content" style="color: var(--survey-text);">{!! $sanitizeHtml($welcomeSettings['content']) !!}</div>
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
        <div class="text-lg font-medium survey-rich-content" id="success-text">
            @if($hasThankYouPage && !empty($thankYouSettings['message']))
                {!! $sanitizeHtml($thankYouSettings['message']) !!}
            @else
                {{ $survey->submit_success_message ?? '感謝您的填寫！' }}
            @endif
        </div>
        {{-- 轉址連結／自動跳轉由提交成功後的 JS 統一插入（REDIRECT_CONFIG），確保跨 render layout 一致。 --}}
    </div>

    {{-- Error banner --}}
    <div id="error-banner" role="alert" aria-live="assertive" class="hidden rounded-lg bg-red-50 border border-red-200 p-4 mb-6">
        <p class="text-sm text-red-700" id="error-text"></p>
    </div>

    @if($progressMode !== 'none' && $pageCount > 0)
    <div id="page-indicator" role="status" aria-live="polite" class="text-sm text-gray-500 text-center mb-4 @if($hasWelcomePage) hidden @endif">
        @if($progressMode === 'bar')
            <progress id="progress-bar" max="{{ $pageCount }}" value="1" aria-label="填答進度" class="h-2 w-full"></progress>
        @elseif($progressMode === 'steps')
            <div id="progress-steps" class="flex justify-center gap-2" role="img" aria-label="第 1 頁，共 {{ $pageCount }} 頁">
                @foreach(range(1, $pageCount) as $step)
                    <span class="progress-step inline-block h-2.5 w-2.5 rounded-full bg-gray-300 {{ $step === 1 ? 'is-active' : '' }}" aria-hidden="true"></span>
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
        <input type="hidden" name="schema_version_id" value="{{ $survey->published_schema_version_id }}">
        <input type="text" name="_hp" autocomplete="off" tabindex="-1" aria-hidden="true" class="hidden" style="display:none">

        @php $questionNo = 0; @endphp
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
                <div class="text-sm text-gray-700 survey-rich-content">{!! $sanitizeHtml($field->description) !!}</div>
            </div>
            @elseif($type === 'quote_block')
            <div class="survey-field" data-field-key="{{ $fk }}" data-field-type="quote_block">
                <blockquote class="border-l-4 border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-gray-700">{!! nl2br(e($field->description)) !!}</blockquote>
            </div>
            @elseif($type === 'divider')
            <div class="survey-field" data-field-key="{{ $fk }}" data-field-type="divider">
                <hr class="border-gray-200">
            </div>
            @else
            @php $questionNo++; @endphp
            <div class="survey-field bg-white rounded-lg border border-gray-200 p-5 shadow-sm"
                 role="group"
                 aria-labelledby="q-label-{{ $fk }}"
                 data-field-key="{{ $fk }}"
                 data-field-type="{{ $type }}"
                 data-field-label="{{ $field->label }}"
                 @if(in_array($type, ['short_text', 'long_text'], true) && isset($field->validation_rules['min_length']))
                 data-min-length="{{ $field->validation_rules['min_length'] }}"
                 @if(!empty($field->validation_rules['pattern_label'])) data-min-length-message="{{ $field->validation_rules['pattern_label'] }}" @endif
                 @endif
                 @if(in_array($type, ['short_text', 'long_text'], true) && isset($field->validation_rules['min_chinese_length']))
                 data-min-chinese-length="{{ $field->validation_rules['min_chinese_length'] }}"
                 @endif
                 @if(in_array($type, ['short_text', 'long_text'], true) && isset($field->validation_rules['max_length']))
                 data-max-length="{{ $field->validation_rules['max_length'] }}"
                 @endif
                 @if($field->is_required)
                 data-field-required="true"
                 aria-required="true"
                 @endif
                 @if($type === 'constant_sum' && isset($field->settings_json['total']))
                 data-constant-sum-total="{{ $field->settings_json['total'] }}"
                 @endif
                 @if($field->show_if_field_key)
                 data-show-if-field="{{ $field->show_if_field_key }}"
                 data-show-if-value="{{ $field->show_if_value }}"
                 @endif>

                <label id="q-label-{{ $fk }}" class="block text-sm font-medium text-gray-900 mb-1">
                    @if($showQuestionNumbers)<span class="text-gray-400 mr-1">{{ $questionNo }}.</span>@endif{{ $field->label }}
                    @if($field->is_required)<span class="text-red-500 ml-0.5">*</span>@endif
                </label>

                @if($field->description)
                    <p class="text-xs text-gray-500 mb-2">{{ $field->description }}</p>
                @endif

                @if($type === 'short_text' || $type === 'email' || $type === 'phone')
                    @php
                        $inputFormat = $field->settings_json['input_format'] ?? null;
                        $isEmailInput = $type === 'email' || $inputFormat === 'email';
                        $isMobileInput = $type === 'phone' || $inputFormat === 'mobile_tw';
                    @endphp
                    <input
                        type="{{ $isEmailInput ? 'email' : ($isMobileInput ? 'tel' : 'text') }}"
                        name="answers[{{ $fk }}]"
                        aria-labelledby="q-label-{{ $fk }}"
                        @if($type === 'short_text') aria-describedby="field-error-{{ $fk }}" @endif
                        placeholder="{{ $field->placeholder ?? '' }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($isMobileInput) inputmode="numeric" minlength="10" maxlength="10" pattern="09[0-9]{8}" @endif
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                    >

                @elseif($type === 'long_text')
                    <textarea
                        name="answers[{{ $fk }}]"
                        aria-labelledby="q-label-{{ $fk }}"
                        aria-describedby="field-error-{{ $fk }}"
                        rows="4"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                    >{{ $field->default_value ?? '' }}</textarea>

                @elseif($type === 'single_choice')
                    @include('survey-core::survey.partials.fields.choice-options-cdn', ['isMultiple' => false])

                @elseif($type === 'multiple_choice')
                    @include('survey-core::survey.partials.fields.choice-options-cdn', ['isMultiple' => true])

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        @if($field->is_required) required @endif
                        data-jump-field="{{ $fk }}"
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        <option value="">請選擇</option>
                        @foreach($field->displayOptionGroups($shuffleSeed ?? null) as $optGroup)
                            @php $groupedOptions = collect($optGroup['options'])->reject(fn ($o) => $o['is_hidden']); @endphp
                            @continue($groupedOptions->isEmpty())
                            @if($optGroup['label'])<optgroup label="{{ $optGroup['label'] }}">@endif
                            @foreach($groupedOptions as $option)
                                @php
                                    $used = $optionUsage[$fk][$option['value']] ?? 0;
                                    $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
                                @endphp
                                <option value="{{ $option['value'] }}" @if($field->default_value === $option['value']) selected @endif @if($isFull) disabled @endif>
                                    {{ $option['label'] }}@if($isFull)（已額滿）@endif
                                </option>
                            @endforeach
                            @if($optGroup['label'])</optgroup>@endif
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    @php
                        $ratingCount = (int)($field->settings_json['count'] ?? 5);
                        $ratingShape = $field->settings_json['shape'] ?? 'star';
                        $ratingShowNumbers = (bool)($field->settings_json['show_numbers'] ?? false);
                        $ratingIcons = ['star' => '★', 'heart' => '♥', 'check' => '✔', 'thumb' => '👍'];
                        $ratingIcon  = $ratingIcons[$ratingShape] ?? '★';
                        $ratingId    = 'rating_' . $fk;
                    @endphp
                    <div class="survey-rating-stars mt-1" data-rating-id="{{ $ratingId }}" style="--rating-count: {{ $ratingCount }};">
                        @foreach(range(1, $ratingCount) as $star)
                            <label class="survey-rating-star-label shape-{{ $ratingShape }}" title="{{ $star }} 分">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $star }}"
                                    aria-label="{{ $star }} 分"
                                    @if($field->is_required) required @endif
                                    class="sr-only survey-rating-radio">
                                @if($ratingShowNumbers)
                                    <span class="survey-rating-star-number">{{ $star }}</span>
                                @endif
                                <span class="survey-rating-star-icon">{{ $ratingIcon }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'date')
                    <input type="date" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                @elseif($type === 'time')
                    <input type="time" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                @elseif($type === 'number')
                    <div class="flex items-center gap-2">
                        <input type="number" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                            value="{{ $field->default_value ?? '' }}"
                            min="{{ $field->settings_json['min'] ?? '' }}"
                            max="{{ $field->settings_json['max'] ?? '' }}"
                            step="{{ $field->settings_json['step'] ?? '1' }}"
                            @if($field->is_required) required @endif
                            class="min-w-0 flex-1 rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        @if(!empty($field->settings_json['unit']))
                            <span class="shrink-0 whitespace-nowrap text-sm text-gray-500">{{ $field->settings_json['unit'] }}</span>
                        @endif
                    </div>
                @elseif($type === 'linear_scale')
                    @php
                        $scaleMin = $field->settings_json['min'] ?? 1;
                        $scaleMax = $field->settings_json['max'] ?? 5;
                        $scaleStep = $field->settings_json['step'] ?? 1;
                        $scaleDefault = $field->default_value ?? $scaleMin;
                    @endphp
                    <div class="survey-linear-scale">
                        <span class="survey-linear-scale-value" data-linear-scale-value>{{ $scaleDefault }}</span>
                        <input type="range" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                            value="{{ $scaleDefault }}"
                            min="{{ $scaleMin }}"
                            max="{{ $scaleMax }}"
                            step="{{ $scaleStep }}"
                            @if($field->is_required) required @endif
                            class="survey-linear-scale-input"
                            data-linear-scale-input>
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>{{ $field->settings_json['low_label'] ?? $scaleMin }}</span>
                            <span>{{ $field->settings_json['high_label'] ?? $scaleMax }}</span>
                        </div>
                    </div>
                @elseif($type === 'constant_sum')
                    <div class="space-y-2">
                        @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
                            @continue($option['is_hidden'])
                            <label class="flex items-center gap-3">
                                <span class="min-w-0 flex-1 text-sm text-gray-700">{{ $option['label'] }}</span>
                                <input type="number"
                                    name="answers[{{ $fk }}][{{ $option['value'] }}]"
                                    step="any"
                                    @if($field->is_required) required @endif
                                    class="w-28 shrink-0 rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                                @if(!empty($field->settings_json['unit']))
                                    <span class="shrink-0 whitespace-nowrap text-sm text-gray-500">{{ $field->settings_json['unit'] }}</span>
                                @endif
                            </label>
                        @endforeach
                        @if(isset($field->settings_json['total']))
                            <div class="survey-constant-sum-summary" data-constant-sum-summary>
                                <span>目前合計 <span data-constant-sum-current>0</span></span>
                                <span>目標 {{ $field->settings_json['total'] }}</span>
                                <strong data-constant-sum-status>剩餘 {{ $field->settings_json['total'] }}</strong>
                            </div>
                        @endif
                    </div>
                @elseif($type === 'cascade_select')
                    @php
                        $cascadeLevels = $field->settings_json['cascade_levels'] ?? [];
                        $cascadeData = $field->settings_json['cascade_data'] ?? [];
                    @endphp
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                         data-cascade-field="{{ $fk }}"
                         data-cascade-data='@json($cascadeData)'>
                        @foreach($cascadeLevels as $levelIndex => $level)
                            @php $levelId = (string) ($level['id'] ?? 'level_' . ($levelIndex + 1)); @endphp
                            <select
                                name="answers[{{ $fk }}][{{ $levelId }}]"
                                data-cascade-level="{{ $levelIndex }}"
                                @if($field->is_required) required @endif
                                @if($levelIndex > 0) disabled @endif
                                class="w-full rounded-md border-gray-300 shadow-sm text-sm px-3 py-2 border focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                                <option value="">{{ $level['label'] ?? '請選擇' }}</option>
                            </select>
                        @endforeach
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
                                    <input type="radio" name="answers[{{ $fk }}]" value="{{ $score }}" aria-label="{{ $score }} 分" class="sr-only survey-nps-radio" @if($field->is_required) required @endif>
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
                        <table class="w-full min-w-md text-sm" aria-label="{{ $field->label }}">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="sr-only">{{ $field->label }}</span></th>
                                    @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                        <th scope="col" class="px-2 py-2 text-center font-medium text-gray-600">{{ $col['label'] ?? '' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($field->displayMatrixRows($shuffleSeed ?? null) as $row)
                                    <tr>
                                        <th scope="row" class="px-2 py-2 text-left font-medium text-gray-700">{{ $row['label'] ?? '' }}</th>
                                        @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                            <td class="px-2 py-2 text-center">
                                                <input
                                                    type="{{ $type === 'matrix_multi' ? 'checkbox' : 'radio' }}"
                                                    name="answers[{{ $fk }}][{{ $row['id'] ?? '' }}]{{ $type === 'matrix_multi' ? '[]' : '' }}"
                                                    value="{{ $col['id'] ?? '' }}"
                                                    aria-label="{{ ($row['label'] ?? '') . '：' . ($col['label'] ?? '') }}"
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
                    <p class="sr-only" id="ranking-help-{{ $fk }}">使用每個項目的「上移」「下移」按鈕調整排序。</p>
                    <div class="survey-ranking-list space-y-2" data-ranking-list="{{ $fk }}" role="group" aria-describedby="ranking-help-{{ $fk }}">
                        @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
                            <div class="survey-ranking-item flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2" draggable="true" data-ranking-item data-ranking-option="{{ $option['value'] }}">
                                <span class="survey-ranking-position" data-ranking-position></span>
                                <span class="survey-ranking-handle" aria-hidden="true">☰</span>
                                <span class="survey-ranking-label text-sm text-gray-700">{{ $option['label'] }}</span>
                                <button type="button" class="survey-ranking-move" data-ranking-move="up" aria-label="將「{{ $option['label'] }}」上移">↑</button>
                                <button type="button" class="survey-ranking-move" data-ranking-move="down" aria-label="將「{{ $option['label'] }}」下移">↓</button>
                            </div>
                        @endforeach
                        <input type="hidden" name="answers[{{ $fk }}]" data-ranking-value="{{ $fk }}">
                    </div>
                @elseif($type === 'selection_based')
                    @php $sourceKey = $field->settings_json['source_field_key'] ?? ''; @endphp
                    <div class="space-y-2 mt-1" data-selection-field="{{ $fk }}" data-selection-source="{{ $sourceKey }}" @if($field->is_required) data-selection-required="1" @endif>
                        <p class="text-sm text-gray-400" data-selection-empty>請先回答來源題目，這裡會顯示可複選的選項。</p>
                    </div>
                @elseif($type === 'file_upload')
                    <div class="space-y-2">
                        <input type="file" data-file-upload-field="{{ $fk }}" @if($fileUploadAccept($field)) accept="{{ $fileUploadAccept($field) }}" @endif class="survey-file-input">
                        <button
                            type="button"
                            class="survey-file-dropzone"
                            data-file-upload-zone="{{ $fk }}"
                            aria-describedby="file-upload-help-{{ $fk }}"
                        >
                            <span class="survey-file-icon" aria-hidden="true">☁</span>
                            <span class="survey-file-title">選擇檔案或將檔案拖曳至此</span>
                            <span class="survey-file-limit">{{ $fileUploadSizeLabel($field) }}</span>
                            <span id="file-upload-help-{{ $fk }}" class="survey-file-format">檔案格式：{{ $fileUploadFormatLabel($field) }}</span>
                        </button>
                        <p class="survey-file-status hidden" data-file-upload-status="{{ $fk }}" aria-live="polite"></p>
                    </div>
                    <input type="hidden" name="answers[{{ $fk }}][media_id]" data-file-media-id="{{ $fk }}">
                    <input type="hidden" name="answers[{{ $fk }}][upload_token]" data-file-upload-token="{{ $fk }}">
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

                <p id="field-error-{{ $fk }}" class="text-xs text-red-500 mt-1 hidden field-error" data-field="{{ $fk }}" role="status" aria-live="polite"></p>
            </div>
            @endif
            @endforeach
        </div>
        @endforeach

        {{-- Terms checkbox --}}
        @if($hasTerms)
        <div id="terms-row" class="rounded-lg bg-white border border-gray-200 p-4 shadow-sm @if($isMultiPage) hidden @endif" style="background: var(--survey-surface); border-color: var(--survey-border); border-radius: var(--survey-radius);">
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
                class="survey-themed-accent-outline inline-flex items-center gap-2 rounded-md border bg-white px-6 py-2.5 text-sm font-semibold shadow-sm hover:bg-gray-50 hidden">
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
