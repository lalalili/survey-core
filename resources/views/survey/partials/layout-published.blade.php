
{{-- ======================================================= PUBLISHED CSS LAYOUT ===== --}}
<div id="main-content" tabindex="-1" class="survey-container">

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
            <input id="password-input" type="password" placeholder="輸入密碼" aria-label="問卷密碼" aria-describedby="password-error" class="survey-input" style="max-width:220px;">
            <button type="button" id="btn-unlock" class="survey-btn survey-btn--primary" style="padding:0.5rem 1.25rem;">解鎖</button>
        </div>
        <p id="password-error" role="alert" aria-live="assertive" class="survey-field-error" style="display:none;margin-top:8px;">密碼不正確，請重試。</p>
    </div>
    <div id="after-gate" class="survey-hidden">
    @endif

    <div id="success-message" class="survey-banner survey-banner--success survey-hidden">
        <p id="success-text">{{ $survey->submit_success_message ?? '感謝您的填寫！' }}</p>
    </div>

    <div id="error-banner" role="alert" aria-live="assertive" class="survey-banner survey-banner--error survey-hidden">
        <p id="error-text"></p>
    </div>

    @if($isMultiPage)
    <p id="page-indicator" role="status" aria-live="polite" class="survey-page-indicator">
        第 <span id="current-page-label">1</span> 頁，共 {{ $pageCount }} 頁
    </p>
    @endif

    <form id="survey-form" novalidate>
        <input type="hidden" name="schema_version_id" value="{{ $survey->published_schema_version_id }}">
        <input type="text" name="_hp" autocomplete="off" tabindex="-1" aria-hidden="true" class="survey-hidden" style="display:none">

        @php $questionNo = 0; @endphp
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
                <div class="survey-description-block survey-rich-content">{!! $sanitizeHtml($field->description) !!}</div>
            </div>
            @elseif($type === 'quote_block')
            <div class="survey-field survey-quote-block" data-field-key="{{ $fk }}" data-field-type="quote_block">
                <blockquote>{!! nl2br(e($field->description)) !!}</blockquote>
            </div>
            @elseif($type === 'divider')
            <div class="survey-field survey-divider" data-field-key="{{ $fk }}" data-field-type="divider">
                <hr>
            </div>
            @else
            @php $questionNo++; @endphp
            <div class="survey-field survey-field-card"
                 role="group"
                 aria-labelledby="q-label-{{ $fk }}"
                 data-field-key="{{ $fk }}"
                 data-field-type="{{ $type }}"
                 data-field-label="{{ $field->label }}"
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

                <label id="q-label-{{ $fk }}" class="survey-field-label">
                    @if($showQuestionNumbers)<span class="survey-question-no">{{ $questionNo }}.</span> @endif{{ $field->label }}
                    @if($field->is_required)<span class="survey-field-required">*</span>@endif
                </label>

                @if($field->description)
                    <p class="survey-field-description">{{ $field->description }}</p>
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
                        placeholder="{{ $field->placeholder ?? '' }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($isMobileInput) inputmode="numeric" minlength="10" maxlength="10" pattern="09[0-9]{8}" @endif
                        @if($field->is_required) required @endif
                        class="survey-input"
                    >

                @elseif($type === 'long_text')
                    <textarea name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}" rows="4"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-textarea">{{ $field->default_value ?? '' }}</textarea>

                @elseif($type === 'single_choice')
                    @include('survey-core::survey.partials.fields.choice-options-published', ['isMultiple' => false])

                @elseif($type === 'multiple_choice')
                    @include('survey-core::survey.partials.fields.choice-options-published', ['isMultiple' => true])

                @elseif($type === 'select')
                    <select name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        @if($field->is_required) required @endif
                        data-jump-field="{{ $fk }}"
                        class="survey-select">
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
                    <div class="survey-rating-stars" data-rating-id="{{ $ratingId }}">
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
                        class="survey-input">
                @elseif($type === 'time')
                    <input type="time" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="survey-input">
                @elseif($type === 'number')
                    <input type="number" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                        value="{{ $field->default_value ?? '' }}"
                        min="{{ $field->settings_json['min'] ?? '' }}"
                        max="{{ $field->settings_json['max'] ?? '' }}"
                        step="{{ $field->settings_json['step'] ?? '1' }}"
                        @if($field->is_required) required @endif
                        class="survey-input">
                @elseif($type === 'linear_scale')
                    @php
                        $scaleMin = $field->settings_json['min'] ?? 1;
                        $scaleMax = $field->settings_json['max'] ?? 5;
                        $scaleStep = $field->settings_json['step'] ?? 1;
                        $scaleDefault = $field->default_value ?? $scaleMin;
                    @endphp
                    <div class="survey-choices survey-linear-scale">
                        <span class="survey-linear-scale-value" data-linear-scale-value>{{ $scaleDefault }}</span>
                        <input type="range" name="answers[{{ $fk }}]" aria-labelledby="q-label-{{ $fk }}"
                            value="{{ $scaleDefault }}"
                            min="{{ $scaleMin }}"
                            max="{{ $scaleMax }}"
                            step="{{ $scaleStep }}"
                            @if($field->is_required) required @endif
                            class="survey-linear-scale-input"
                            data-linear-scale-input>
                        <div class="survey-nps-labels">
                            <span>{{ $field->settings_json['low_label'] ?? $scaleMin }}</span>
                            <span>{{ $field->settings_json['high_label'] ?? $scaleMax }}</span>
                        </div>
                    </div>
                @elseif($type === 'constant_sum')
                    <div class="survey-choices">
                        @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
                            @continue($option['is_hidden'])
                            <label class="survey-choice-label">
                                <span>{{ $option['label'] }}</span>
                                <input type="number" name="answers[{{ $fk }}][{{ $option['value'] }}]" step="any" @if($field->is_required) required @endif class="survey-input">
                                @if(!empty($field->settings_json['unit']))
                                    <span>{{ $field->settings_json['unit'] }}</span>
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
                    <div class="survey-choices"
                         data-cascade-field="{{ $fk }}"
                         data-cascade-data='@json($cascadeData)'>
                        @foreach($cascadeLevels as $levelIndex => $level)
                            @php $levelId = (string) ($level['id'] ?? 'level_' . ($levelIndex + 1)); @endphp
                            <select
                                name="answers[{{ $fk }}][{{ $levelId }}]"
                                data-cascade-level="{{ $levelIndex }}"
                                @if($field->is_required) required @endif
                                @if($levelIndex > 0) disabled @endif
                                class="survey-select">
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
                    <div style="overflow-x:auto">
                        <table class="survey-matrix" aria-label="{{ $field->label }}">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="sr-only">{{ $field->label }}</span></th>
                                    @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                        <th scope="col">{{ $col['label'] ?? '' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($field->displayMatrixRows($shuffleSeed ?? null) as $row)
                                    <tr>
                                        <th scope="row">{{ $row['label'] ?? '' }}</th>
                                        @foreach(($field->settings_json['matrix_cols'] ?? []) as $col)
                                            <td>
                                                <input
                                                    type="{{ $type === 'matrix_multi' ? 'checkbox' : 'radio' }}"
                                                    name="answers[{{ $fk }}][{{ $row['id'] ?? '' }}]{{ $type === 'matrix_multi' ? '[]' : '' }}"
                                                    value="{{ $col['id'] ?? '' }}"
                                                    aria-label="{{ ($row['label'] ?? '') . '：' . ($col['label'] ?? '') }}"
                                                    @if($field->is_required && $type === 'matrix_single') required @endif>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($type === 'ranking')
                    <p class="sr-only" id="ranking-help-{{ $fk }}">使用每個項目的「上移」「下移」按鈕調整排序。</p>
                    <div class="survey-ranking-list" data-ranking-list="{{ $fk }}" role="group" aria-describedby="ranking-help-{{ $fk }}">
                        @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
                            <div class="survey-ranking-item" draggable="true" data-ranking-item data-ranking-option="{{ $option['value'] }}">
                                <span class="survey-ranking-position" data-ranking-position></span>
                                <span class="survey-ranking-handle" aria-hidden="true">☰</span>
                                <span class="survey-ranking-label">{{ $option['label'] }}</span>
                                <button type="button" class="survey-ranking-move" data-ranking-move="up" aria-label="將「{{ $option['label'] }}」上移">↑</button>
                                <button type="button" class="survey-ranking-move" data-ranking-move="down" aria-label="將「{{ $option['label'] }}」下移">↓</button>
                            </div>
                        @endforeach
                        <input type="hidden" name="answers[{{ $fk }}]" data-ranking-value="{{ $fk }}">
                    </div>
                @elseif($type === 'selection_based')
                    @php $sourceKey = $field->settings_json['source_field_key'] ?? ''; @endphp
                    <div class="survey-choices" data-selection-field="{{ $fk }}" data-selection-source="{{ $sourceKey }}" @if($field->is_required) data-selection-required="1" @endif>
                        <p class="survey-help" data-selection-empty>請先回答來源題目，這裡會顯示可複選的選項。</p>
                    </div>
                @elseif($type === 'file_upload')
                    <div class="survey-choices">
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

                <p class="survey-field-error field-error" data-field="{{ $fk }}" role="alert" aria-live="assertive"></p>
            </div>
            @endif
            @endforeach
        </div>
        @endforeach

        {{-- Terms checkbox (published mode) --}}
        @if($hasTerms)
        <div id="terms-row" class="survey-field-card @if($isMultiPage) survey-hidden @endif" style="margin-bottom:1rem;">
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
