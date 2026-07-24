<script>
(function () {
    // ─── Config ──────────────────────────────────────────────────────────────
    var IS_MULTI_PAGE = {{ $isMultiPage ? 'true' : 'false' }};
    var PAGES_DATA    = @json($pagesData);   // [{id, title}, ...]
    var ALL_PAGE_KEYS = @json($allQuestionPageKeys);
    var BRANCHING     = @json($branchingMap);
    var JUMP_MAP      = @json($jumpMap);     // {field_key: {value: {type, target_page_id?}}}
    var PAGE_JUMP_MAP = @json($pageJumpMap);
    var THANK_YOU_MESSAGE = @json($hasThankYouPage ? ($thankYouSettings['message'] ?? null) : null);
    var REDIRECT_CONFIG = @json($redirectConfig);   // {url, mode:'link'|'auto', delay} or null

    // 提交成功後依 REDIRECT_CONFIG 統一處理轉址（連結／自動跳轉），對所有 success-message
    // layout 一致插入，避免不同 render 模式行為不同。
    function applyThankYouRedirect() {
        if (!REDIRECT_CONFIG || !REDIRECT_CONFIG.url) { return; }
        var url = String(REDIRECT_CONFIG.url);
        if (!/^https?:\/\//i.test(url)) { return; }   // 前台再次過濾，僅允許 http(s)
        var auto = REDIRECT_CONFIG.mode === 'auto';
        var notes = [];
        document.querySelectorAll('#success-message').forEach(function (box) {
            if (auto) {
                var note = document.createElement('p');
                note.className = 'mt-4 text-sm';
                box.appendChild(note);
                notes.push(note);
            }
            var link = document.createElement('a');
            link.href = url;
            link.rel = 'noopener';
            link.className = 'survey-themed-accent mt-2 inline-flex rounded-md border px-4 py-2 text-sm font-medium hover:opacity-90';
            link.textContent = auto ? '立即前往' : '繼續';
            box.appendChild(link);
        });
        if (!auto) { return; }
        var remain = Math.max(0, Math.min(30, parseInt(REDIRECT_CONFIG.delay, 10) || 0));
        var render = function () {
            var txt = remain > 0 ? (remain + ' 秒後自動前往…') : '正在前往…';
            notes.forEach(function (n) { n.textContent = txt; });
        };
        render();
        if (remain <= 0) { window.location.href = url; return; }
        var timer = setInterval(function () {
            remain -= 1;
            render();
            if (remain <= 0) { clearInterval(timer); window.location.href = url; }
        }, 1000);
    }
    var STARTED_AT = Date.now();
    var SURVEY_QUERY = @json($surveyQuery);
    var DRAFT_STORAGE_KEY = [
        'lalalili-survey-draft',
        @json($survey->public_key),
        @json($survey->published_schema_version_id),
        SURVEY_QUERY.t || 'anonymous',
        SURVEY_QUERY.collector || 'direct',
    ].join(':');
    var PASSWORD_URL = @json(isset($collector) && $collector ? route('survey.collector.password', $collector->slug) : route('survey.password', $survey->public_key));
    var SUBMIT_URL = @json(route('survey.submit', $survey->public_key));
    var UPLOAD_URL = @json(route('survey.upload', $survey->public_key));
    var EVENTS_URL = @json(route('survey.events', $survey->public_key));
    var SCHEMA_VERSION_ID = @json($survey->published_schema_version_id);

    // ─── Access controls ──────────────────────────────────────────────────────
    var HAS_PASSWORD_GATE = {{ $hasPassword ? 'true' : 'false' }};
    var HAS_TERMS = {{ $hasTerms ? 'true' : 'false' }};
    var HAS_TURNSTILE = {{ ($turnstileEnabled && $turnstiteSiteKey) ? 'true' : 'false' }};
    var ALLOW_BACK = {{ $allowBack ? 'true' : 'false' }};
    var turnstileToken = null;

    // Turnstile callback (called by widget on success)
    window.onTurnstileSuccess = function (token) { turnstileToken = token; };

    // Password gate
    var passwordGate = document.getElementById('password-gate');
    var afterGate    = document.getElementById('after-gate');
    var btnUnlock    = document.getElementById('btn-unlock');
    var passwordInput = document.getElementById('password-input');
    var passwordError = document.getElementById('password-error');

    function appendSurveyQuery(url) {
        var params = new URLSearchParams(SURVEY_QUERY || {});
        var queryString = params.toString();
        if (!queryString) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + queryString;
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    }

    function selectorEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return value.replace(/["\\]/g, '\\$&');
    }

    function fieldKeyFromErrorKey(errorKey) {
        return String(errorKey || '').split('.')[0];
    }

    function findFieldElement(fieldKey) {
        return document.querySelector('[data-field-key="' + selectorEscape(fieldKeyFromErrorKey(fieldKey)) + '"]');
    }

    function formatSurveyNumber(value) {
        if (!Number.isFinite(value)) { return ''; }
        return String(Math.round(value * 100000) / 100000);
    }

    function calculateConstantSumField(fieldEl) {
        var inputs = Array.from(fieldEl.querySelectorAll('input[type="number"]:not(:disabled)'));
        var sum = 0;

        inputs.forEach(function (input) {
            var number = Number(input.value);
            if (input.value !== '' && Number.isFinite(number)) {
                sum += number;
            }
        });

        return {
            inputs: inputs,
            sum: sum,
            total: Number(fieldEl.getAttribute('data-constant-sum-total')),
        };
    }

    function updateConstantSumSummary(fieldEl) {
        var summary = fieldEl.querySelector('[data-constant-sum-summary]');
        if (!summary) { return; }

        var state = calculateConstantSumField(fieldEl);
        var currentEl = summary.querySelector('[data-constant-sum-current]');
        var statusEl = summary.querySelector('[data-constant-sum-status]');

        if (currentEl) {
            currentEl.textContent = formatSurveyNumber(state.sum);
        }

        if (!Number.isFinite(state.total)) {
            summary.setAttribute('data-status', 'neutral');
            if (statusEl) { statusEl.textContent = '尚未設定合計目標'; }
            return;
        }

        var diff = state.total - state.sum;

        if (Math.abs(diff) <= 0.00001) {
            summary.setAttribute('data-status', 'matched');
            if (statusEl) { statusEl.textContent = '合計符合目標'; }
            return;
        }

        summary.setAttribute('data-status', diff > 0 ? 'under' : 'over');
        if (statusEl) {
            statusEl.textContent = diff > 0
                ? '剩餘 ' + formatSurveyNumber(diff)
                : '超出 ' + formatSurveyNumber(Math.abs(diff));
        }
    }

    async function recordSurveyEvent(eventName, extra) {
        try {
            await fetch(appendSurveyQuery(EVENTS_URL), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify(Object.assign({ event: eventName }, extra || {})),
            });
        } catch (e) {
            // Analytics events must never block the respondent.
        }
    }

    if (passwordGate && HAS_PASSWORD_GATE) {
        if (btnUnlock) {
            btnUnlock.addEventListener('click', async function () {
                var val = passwordInput ? passwordInput.value : '';
                var res = await fetch(PASSWORD_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ password: val }),
                });

                if (res.ok) {
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
        if (!inp) { return null; }

        // radio / checkbox 沒有任何 :checked 就是「未作答」，必須回傳 null。
        // 這裡若沿用下面的 inp.value fallback，會取到 DOM 中第一個選項的 value——
        // 使用者還沒選，顯示條件（equals 第一個選項）就已成立，被條件控制的題目
        // 一載入就顯示；頁面跳轉規則也會誤用第一個選項的跳轉目標。
        if (inp.type === 'radio' || inp.type === 'checkbox') { return null; }

        return inp.value;
    }

    // ─── Jump logic ───────────────────────────────────────────────────────────
    function nextRenderablePageKey(pageKey) {
        if (!pageKey) { return null; }

        var visibleIdx = PAGES_DATA.findIndex(function (p) { return p.id === pageKey; });
        if (visibleIdx !== -1) { return pageKey; }

        var allIdx = ALL_PAGE_KEYS.findIndex(function (id) { return id === pageKey; });
        if (allIdx === -1) { return null; }

        for (var i = allIdx + 1; i < ALL_PAGE_KEYS.length; i += 1) {
            var nextKey = ALL_PAGE_KEYS[i];
            if (PAGES_DATA.some(function (p) { return p.id === nextKey; })) {
                return nextKey;
            }
        }

        return null;
    }

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

            if (action && action.type === 'go_to_page') { nextKey = nextRenderablePageKey(action.target_page_id || null); }
        }

        var pageRules = PAGE_JUMP_MAP[fromPageKey] || [];
        for (var i = 0; i < pageRules.length; i += 1) {
            var rule = pageRules[i];
            var ruleCondition = rule.condition || {};

            // 沒有任何條件的跳轉規則一律略過，與後端 JumpLogicResolver 一致。
            // conditionGroupPasses 對空的 conditions 回傳 true（顯示條件的語意是
            // 「沒設條件就顯示」），沿用會讓還沒設定條件的規則無條件改變流程。
            if (!Array.isArray(ruleCondition.conditions) || ruleCondition.conditions.length === 0) { continue; }

            if (!conditionGroupPasses(ruleCondition)) { continue; }

            if (rule.action && rule.action.type === 'end_survey') { return 'END_SURVEY'; }
            if (rule.action && rule.action.type === 'go_to_page') { return nextRenderablePageKey(rule.action.target_page_id || null); }
        }

        return nextKey;
    }

    // ─── Page display ─────────────────────────────────────────────────────────
    function updateNavButtons() {
        var prevBtn   = document.getElementById('btn-prev');
        var nextBtn   = document.getElementById('btn-next');
        var submitBtn = document.getElementById('submit-btn');
        var navRight  = document.getElementById('nav-right');
        var termsRow  = document.getElementById('terms-row');

        // Show prev when there's history and allow_back is enabled
        if (prevBtn) {
            if (ALLOW_BACK && pageStack.length > 0) { show(prevBtn); } else { hide(prevBtn); }
        }

        // Update nav-right alignment
        if (navRight) {
            if (pageStack.length > 0) {
                navRight.style.marginLeft = '';
            } else {
                navRight.style.marginLeft = 'auto';
            }
        }

        var nextKey = resolveNextPageKey(currentPageKey);
        var isLastPage = (nextKey === null || nextKey === 'END_SURVEY');

        // Terms checkbox only makes sense right before submitting, so only show it on the last page
        if (termsRow) {
            if (isLastPage) { show(termsRow); } else { hide(termsRow); }
        }

        if (!nextBtn || !submitBtn) { return; }

        if (isLastPage) {
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
                step.classList.add('is-active');
            } else {
                step.classList.remove('is-active');
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

        clearErrors();
        var valid = true;
        pageEl.querySelectorAll(
            'input[required]:not(:disabled), textarea[required]:not(:disabled), select[required]:not(:disabled)'
        ).forEach(function (inp) {
            if (!inp.checkValidity()) {
                inp.reportValidity();
                valid = false;
            }
        });

        var errors = {};
        pageEl.querySelectorAll('[data-field-type="constant_sum"][data-constant-sum-total]').forEach(function (fieldEl) {
            var message = validateConstantSumField(fieldEl);
            if (message) {
                errors[fieldEl.getAttribute('data-field-key')] = [message];
                valid = false;
            }
        });

        if (Object.keys(errors).length > 0) {
            showFieldErrors(errors);
            focusFirstError(errors);
        }

        return valid;
    }

    function validateConstantSumField(fieldEl) {
        var total = Number(fieldEl.getAttribute('data-constant-sum-total'));
        if (!Number.isFinite(total)) { return null; }

        var fieldLabel = fieldEl.getAttribute('data-field-label') || '此總計題';
        var required = fieldEl.getAttribute('data-field-required') === 'true';
        var state = calculateConstantSumField(fieldEl);
        var inputs = state.inputs;
        var hasAnyValue = inputs.some(function (input) { return input.value !== ''; });

        if (!required && !hasAnyValue) { return null; }

        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var optionLabel = input.closest('label')?.querySelector('span')?.textContent?.trim() || '選項';

            if (required && input.value === '') {
                return '「' + fieldLabel + '」的「' + optionLabel + '」尚未填寫，請填入數字。';
            }

            if (input.value === '') { continue; }

            var number = Number(input.value);
            if (!Number.isFinite(number)) {
                return '「' + fieldLabel + '」的「' + optionLabel + '」必須是數字。';
            }
        }

        if (Math.abs(state.sum - total) > 0.00001) {
            return '「' + fieldLabel + '」目前合計為 ' + formatSurveyNumber(state.sum) + '，需等於 ' + formatSurveyNumber(total) + '，請調整各項數字。';
        }

        return null;
    }

    // ─── Branching (show_if) ──────────────────────────────────────────────────
    function valueMatches(current, expected) {
        if (Array.isArray(current)) { return current.includes(expected); }
        return current === expected;
    }

    function isUnanswered(value) {
        return value === null || value === '' || (Array.isArray(value) && value.length === 0);
    }

    function conditionPasses(condition) {
        var current = getAnswerValue(condition.field_key || '');
        var expected = condition.value;
        var op = condition.op || 'equals';

        if (op === 'is_empty') { return isUnanswered(current); }
        if (op === 'is_not_empty') { return !isUnanswered(current); }

        // 目標題目未作答時，除了 is_empty / is_not_empty 之外一律不成立，
        // 與後端 ConditionGroupEvaluator 保持一致。少了這道守衛，
        // not_equals / not_contains 會在未作答時成立，而 less_than 會因為
        // Number(null) === 0 而誤判「評分小於 N」成立。
        if (isUnanswered(current)) { return false; }

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
        return valueMatches(current, expected);
    }

    function conditionGroupPasses(group) {
        var conditions = Array.isArray(group.conditions) ? group.conditions : [];
        if (conditions.length === 0) { return true; }
        // Each entry is a leaf condition or a nested group (recurse on the latter).
        var evaluate = function (node) {
            return (node && Array.isArray(node.conditions)) ? conditionGroupPasses(node) : conditionPasses(node);
        };
        if ((group.logic || 'and') === 'or') {
            return conditions.some(evaluate);
        }
        return conditions.every(evaluate);
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
            var field    = fieldKeyFromErrorKey(entry[0]);
            var messages = entry[1];
            var el = document.querySelector('.field-error[data-field="' + selectorEscape(field) + '"]');
            if (!el) { return; }
            el.textContent = Array.isArray(messages) ? messages[0] : messages;
            if (isCdnMode()) { el.classList.remove('hidden'); }
            else { el.classList.add('visible'); }
        });
    }

    function pageKeyForField(fieldKey) {
        var fieldEl = findFieldElement(fieldKey);
        var pageEl = fieldEl ? fieldEl.closest('[data-page-key]') : null;

        return pageEl ? pageEl.getAttribute('data-page-key') : null;
    }

    function focusFirstError(errors) {
        var firstFieldKey = Object.keys(errors)[0];
        var fieldEl = findFieldElement(firstFieldKey);
        if (!fieldEl) { return; }

        fieldEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

        var firstInput = fieldEl.querySelector('input:not(:disabled), textarea:not(:disabled), select:not(:disabled), button:not(:disabled)');
        if (firstInput) { firstInput.focus({ preventScroll: true }); }
    }

    function showFieldErrorsOnFirstErrorPage(errors) {
        var firstFieldKey = Object.keys(errors)[0];
        var pageKey = pageKeyForField(firstFieldKey);

        if (pageKey && pageKey !== currentPageKey) {
            pageStack = pageStack.filter(function (key) { return key !== pageKey; });
            showPage(pageKey);
        }

        showFieldErrors(errors);
        focusFirstError(errors);
    }

    function rebuildPageStackForRestoredPage(targetPageKey) {
        var firstPageKey = PAGES_DATA.length > 0 ? PAGES_DATA[0].id : null;
        if (!targetPageKey || !firstPageKey || targetPageKey === firstPageKey) {
            return [];
        }

        var stack = [];
        var cursor = firstPageKey;
        var visited = {};

        while (cursor && cursor !== targetPageKey && !visited[cursor]) {
            visited[cursor] = true;
            stack.push(cursor);

            var nextKey = resolveNextPageKey(cursor);
            if (!nextKey || nextKey === 'END_SURVEY') {
                return [];
            }

            cursor = nextKey;
        }

        return cursor === targetPageKey ? stack : [];
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

    function applyScalarAnswer(fieldKey, value) {
        var radio = document.querySelector('input[type="radio"][name="answers[' + fieldKey + ']"][value="' + selectorEscape(String(value)) + '"]');
        if (radio) {
            radio.checked = true;
            return;
        }

        var input = document.querySelector('[name="answers[' + fieldKey + ']"]');
        if (input) {
            input.value = value === null || value === undefined ? '' : String(value);
        }
    }

    function applyArrayAnswer(fieldKey, values) {
        var rankingList = document.querySelector('[data-ranking-list="' + fieldKey + '"]');
        if (rankingList) {
            values.forEach(function (option) {
                var item = rankingList.querySelector('[data-ranking-option="' + selectorEscape(String(option)) + '"]');
                var hiddenValue = rankingList.querySelector('[data-ranking-value]');
                if (!item) { return; }

                if (hiddenValue) {
                    rankingList.insertBefore(item, hiddenValue);
                } else {
                    rankingList.appendChild(item);
                }
            });

            updateRankingValues();
            return;
        }

        document.querySelectorAll('[name="answers[' + fieldKey + '][]"]').forEach(function (input) {
            input.checked = values.map(String).includes(String(input.value));
        });
    }

    function applyObjectAnswer(fieldKey, value) {
        Object.keys(value || {}).forEach(function (childKey) {
            var childValue = value[childKey];

            if (Array.isArray(childValue)) {
                document.querySelectorAll('[name="answers[' + fieldKey + '][' + childKey + '][]"]').forEach(function (input) {
                    input.checked = childValue.map(String).includes(String(input.value));
                });

                return;
            }

            var input = document.querySelector('[name="answers[' + fieldKey + '][' + childKey + ']"]');
            if (input) {
                input.value = childValue === null || childValue === undefined ? '' : String(childValue);
            }
        });
    }

    function restoreDraft() {
        try {
            var raw = window.localStorage.getItem(DRAFT_STORAGE_KEY);
            if (!raw) { return; }

            var draft = JSON.parse(raw);
            var answers = draft && draft.answers ? draft.answers : {};

            Object.keys(answers).forEach(function (fieldKey) {
                var value = answers[fieldKey];

                if (Array.isArray(value)) {
                    applyArrayAnswer(fieldKey, value);
                } else if (value !== null && typeof value === 'object') {
                    applyObjectAnswer(fieldKey, value);
                } else {
                    applyScalarAnswer(fieldKey, value);
                }
            });

            if (draft.page_key && ALL_PAGE_KEYS.includes(draft.page_key)) {
                currentPageKey = draft.page_key;
                pageStack = rebuildPageStackForRestoredPage(draft.page_key);
            }

            updateRankingValues();
            evaluateBranching();
        } catch (e) {
            window.localStorage.removeItem(DRAFT_STORAGE_KEY);
        }
    }

    function persistDraft() {
        try {
            window.localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify({
                answers: collectAnswers(),
                page_key: currentPageKey,
                updated_at: Date.now(),
            }));
        } catch (e) {
            // Draft persistence is best-effort only.
        }
    }

    function clearDraft() {
        try {
            window.localStorage.removeItem(DRAFT_STORAGE_KEY);
        } catch (e) {
            // Draft persistence is best-effort only.
        }
    }

    function updateRankingValues() {
        document.querySelectorAll('[data-ranking-list]').forEach(function (list) {
            var fieldKey = list.getAttribute('data-ranking-list');
            var target = document.querySelector('[data-ranking-value="' + fieldKey + '"]');
            if (!target) { return; }

            var items = Array.prototype.slice.call(list.querySelectorAll('[data-ranking-item]'));
            target.value = items
                .map(function (item) { return item.getAttribute('data-ranking-option'); })
                .filter(Boolean)
                .join(',');

            items.forEach(function (item, index) {
                var position = item.querySelector('[data-ranking-position]');
                var upButton = item.querySelector('[data-ranking-move="up"]');
                var downButton = item.querySelector('[data-ranking-move="down"]');

                if (position) { position.textContent = String(index + 1); }
                if (upButton) { upButton.disabled = index === 0; }
                if (downButton) { downButton.disabled = index === items.length - 1; }
            });
        });
    }

    function getRankingDropTarget(list, pointerY) {
        var items = Array.prototype.slice
            .call(list.querySelectorAll('[data-ranking-item]:not(.is-dragging)'));

        return items.reduce(function (closest, item) {
            var box = item.getBoundingClientRect();
            var offset = pointerY - box.top - box.height / 2;

            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: item };
            }

            return closest;
        }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    }

    function moveRankingItem(button, direction) {
        var item = button.closest('[data-ranking-item]');
        if (!item) { return; }

        var sibling = direction === 'up' ? item.previousElementSibling : item.nextElementSibling;
        while (sibling && !sibling.matches('[data-ranking-item]')) {
            sibling = direction === 'up' ? sibling.previousElementSibling : sibling.nextElementSibling;
        }

        if (direction === 'up' && sibling) {
            item.parentElement.insertBefore(item, sibling);
        }

        if (direction === 'down' && sibling) {
            item.parentElement.insertBefore(sibling, item);
        }

        updateRankingValues();
    }

    function initRankingLists() {
        updateRankingValues();

        document.querySelectorAll('[data-ranking-item]').forEach(function (item) {
            item.addEventListener('dragstart', function (event) {
                item.classList.add('is-dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', item.getAttribute('data-ranking-option') || '');
                }
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                updateRankingValues();
            });
        });

        document.querySelectorAll('[data-ranking-list]').forEach(function (list) {
            list.addEventListener('dragover', function (event) {
                var dragging = list.querySelector('.is-dragging');
                if (!dragging) { return; }

                event.preventDefault();
                var target = getRankingDropTarget(list, event.clientY);
                if (target) {
                    list.insertBefore(dragging, target);
                } else {
                    var hiddenValue = list.querySelector('[data-ranking-value]');
                    if (hiddenValue) {
                        list.insertBefore(dragging, hiddenValue);
                    } else {
                        list.appendChild(dragging);
                    }
                }
            });

            list.addEventListener('drop', function (event) {
                event.preventDefault();
                updateRankingValues();
            });
        });
    }

    function fileUploadElements(fieldKey) {
        return {
            mediaId: document.querySelector('[data-file-media-id="' + fieldKey + '"]'),
            uploadToken: document.querySelector('[data-file-upload-token="' + fieldKey + '"]'),
            filename: document.querySelector('[data-file-filename="' + fieldKey + '"]'),
            size: document.querySelector('[data-file-size="' + fieldKey + '"]'),
            zone: document.querySelector('[data-file-upload-zone="' + fieldKey + '"]'),
            status: document.querySelector('[data-file-upload-status="' + fieldKey + '"]'),
        };
    }

    function setFileUploadStatus(fieldKey, message, isVisible) {
        var status = document.querySelector('[data-file-upload-status="' + fieldKey + '"]');
        if (!status) { return; }
        status.textContent = message || '';
        status.classList.toggle('hidden', !isVisible);
    }

    function clearFileUploadSelection(fieldKey, elements) {
        if (elements.mediaId) { elements.mediaId.value = ''; }
        if (elements.uploadToken) { elements.uploadToken.value = ''; }
        if (elements.filename) { elements.filename.value = ''; }
        if (elements.size) { elements.size.value = ''; }
        if (elements.zone) { elements.zone.classList.remove('is-uploaded'); }
        setFileUploadStatus(fieldKey, '', false);
    }

    async function readJsonResponse(response) {
        var contentType = response.headers.get('content-type') || '';

        if (contentType.indexOf('application/json') === -1) {
            return {};
        }

        try {
            return await response.json();
        } catch (error) {
            return {};
        }
    }

    function formatFileSize(bytes) {
        if (!bytes) { return ''; }
        if (bytes < 1024 * 1024) { return Math.max(1, Math.round(bytes / 1024)) + ' KB'; }
        return (bytes / 1024 / 1024).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1) + ' MB';
    }

    async function updateFileUploadMeta(input) {
        var fieldKey = input.getAttribute('data-file-upload-field');
        var file = input.files && input.files[0] ? input.files[0] : null;
        if (!fieldKey || !file) { return; }
        var elements = fileUploadElements(fieldKey);
        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var body = new FormData();
        body.append('field_key', fieldKey);
        body.append('schema_version_id', SCHEMA_VERSION_ID || '');
        body.append('file', file);

        if (elements.zone) { elements.zone.classList.remove('is-uploaded'); }
        setFileUploadStatus(fieldKey, '上傳中：' + file.name, true);

        try {
            var res = await fetch(appendSurveyQuery(UPLOAD_URL), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: body,
            });
            var data = await readJsonResponse(res);
        } catch (error) {
            clearFileUploadSelection(fieldKey, elements);
            showFieldErrors({ [fieldKey]: ['檔案上傳失敗，請確認網路連線後再試一次。'] });
            return;
        }

        if (!res.ok) {
            clearFileUploadSelection(fieldKey, elements);
            showFieldErrors({ [fieldKey]: [data.message || '檔案上傳失敗。'] });
            return;
        }

        if (elements.mediaId) { elements.mediaId.value = String(data.media_id); }
        if (elements.uploadToken) { elements.uploadToken.value = data.upload_token || ''; }
        if (elements.filename) { elements.filename.value = data.filename || file.name; }
        if (elements.size) { elements.size.value = String(data.size || file.size); }
        if (elements.zone) { elements.zone.classList.add('is-uploaded'); }
        setFileUploadStatus(fieldKey, '已選擇：' + (data.filename || file.name) + (data.size || file.size ? '（' + formatFileSize(data.size || file.size) + '）' : ''), true);
    }

    function parseCascadeData(raw) {
        if (!raw) { return []; }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    }

    function cascadeChildren(nodes, selectedValues, depth) {
        var current = Array.isArray(nodes) ? nodes : [];

        for (var i = 0; i < depth; i += 1) {
            var selected = selectedValues[i];
            var found = current.find(function (node) {
                return String(node.id || node.label || '') === String(selected || '');
            });

            current = found && Array.isArray(found.children) ? found.children : [];
        }

        return current;
    }

    function populateCascadeSelect(select, nodes) {
        var placeholder = select.options[0] ? select.options[0].textContent : '請選擇';
        select.innerHTML = '';

        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder || '請選擇';
        select.appendChild(empty);

        nodes.forEach(function (node) {
            var value = String(node.id || node.label || '');
            if (!value) { return; }

            var option = document.createElement('option');
            option.value = value;
            option.textContent = String(node.label || value);
            select.appendChild(option);
        });
    }

    function updateCascade(container, changedLevel) {
        var data = parseCascadeData(container.getAttribute('data-cascade-data'));
        var selects = Array.from(container.querySelectorAll('[data-cascade-level]'))
            .sort(function (a, b) {
                return Number(a.getAttribute('data-cascade-level')) - Number(b.getAttribute('data-cascade-level'));
            });
        var selectedValues = selects.map(function (select) { return select.value; });

        selects.forEach(function (select, index) {
            if (index === 0 && select.options.length <= 1) {
                populateCascadeSelect(select, data);
            }

            if (index === 0) {
                select.disabled = data.length === 0;
                return;
            }

            if (index <= changedLevel) { return; }

            select.value = '';
            var nodes = cascadeChildren(data, selectedValues, index);
            populateCascadeSelect(select, nodes);
            select.disabled = nodes.length === 0 || !selectedValues[index - 1];
        });
    }

    function initCascadeSelects() {
        document.querySelectorAll('[data-cascade-field]').forEach(function (container) {
            updateCascade(container, -1);
        });
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

        var token = csrfToken();
        var url = appendSurveyQuery(SUBMIT_URL);

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
                    schema_version_id: SCHEMA_VERSION_ID,
                    _elapsed_ms: Date.now() - STARTED_AT,
                    _hp: (document.querySelector('[name="_hp"]') || {}).value || '',
                    _turnstile_token: turnstileToken,
                    _terms_accepted: termsCheckbox ? termsCheckbox.checked : false,
                    collector: SURVEY_QUERY.collector || null,
                }),
            });

            var data = null;
            try {
                data = await res.json();
            } catch {
                data = null;
            }

            var restoreSubmitButton = function () {
                if (submitBtn) { submitBtn.disabled = false; }
                if (spinner) { spinner.style.display = 'none'; }
                if (label) { label.textContent = '送出問卷'; }
            };

            var showErrorBanner = function (message) {
                var errorBanner = document.getElementById('error-banner');
                var errorText   = document.getElementById('error-text');
                if (errorText) { errorText.textContent = message; }
                if (errorBanner) { show(errorBanner); }
            };

            if (res.ok && data) {
                clearDraft();
                hide(document.getElementById('survey-form'));
                hide(document.getElementById('page-indicator'));
                show(document.getElementById('success-message'));
                var successText = document.getElementById('success-text');
                if (successText) {
                    var msg = data.message || THANK_YOU_MESSAGE || successText.innerHTML;
                    if (data.response_number) { msg = msg.replace(/\{\{response_number\}\}/g, data.response_number); }
                    successText.innerHTML = msg;
                }
                applyThankYouRedirect();
            } else if (res.status === 422 && data && data.errors) {
                showFieldErrorsOnFirstErrorPage(data.errors);
                restoreSubmitButton();
            } else if (res.status === 419) {
                // CSRF token 逾時（填答頁開啟過久）：伺服器回傳非 JSON 的逾時頁面。
                showErrorBanner('頁面已閒置過久，請重新整理頁面後再送出（已填內容會保留）。');
                restoreSubmitButton();
            } else if (data && data.message) {
                showErrorBanner(data.message);
                restoreSubmitButton();
            } else {
                // 非預期的非 JSON 回應（例如 500）：避免誤導成「網路錯誤」。
                showErrorBanner('伺服器忙碌中，請稍後再試。');
                restoreSubmitButton();
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
    initCascadeSelects();
    initSignaturePads();
    initRankingLists();
    restoreDraft();

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('[data-file-upload-field]')) { void updateFileUploadMeta(event.target); }
        if (event.target && event.target.matches('[data-cascade-level]')) {
            var cascadeContainer = event.target.closest('[data-cascade-field]');
            if (cascadeContainer) {
                updateCascade(cascadeContainer, Number(event.target.getAttribute('data-cascade-level')));
            }
        }
        evaluateBranching();
        if (IS_MULTI_PAGE) { updateNavButtons(); }
        persistDraft();
    });
    document.addEventListener('click', function (event) {
        var zone = event.target && event.target.closest ? event.target.closest('[data-file-upload-zone]') : null;
        if (!zone) { return; }
        var fieldKey = zone.getAttribute('data-file-upload-zone');
        var input = document.querySelector('[data-file-upload-field="' + fieldKey + '"]');
        if (input) { input.click(); }
    });
    document.addEventListener('dragenter', function (event) {
        var zone = event.target && event.target.closest ? event.target.closest('[data-file-upload-zone]') : null;
        if (!zone) { return; }
        event.preventDefault();
        zone.classList.add('is-dragging');
    });
    document.addEventListener('dragover', function (event) {
        var zone = event.target && event.target.closest ? event.target.closest('[data-file-upload-zone]') : null;
        if (!zone) { return; }
        event.preventDefault();
        zone.classList.add('is-dragging');
    });
    document.addEventListener('dragleave', function (event) {
        var zone = event.target && event.target.closest ? event.target.closest('[data-file-upload-zone]') : null;
        if (!zone || (event.relatedTarget instanceof Node && zone.contains(event.relatedTarget))) { return; }
        zone.classList.remove('is-dragging');
    });
    document.addEventListener('drop', function (event) {
        var zone = event.target && event.target.closest ? event.target.closest('[data-file-upload-zone]') : null;
        if (!zone) { return; }
        event.preventDefault();
        zone.classList.remove('is-dragging');
        var fieldKey = zone.getAttribute('data-file-upload-zone');
        var input = document.querySelector('[data-file-upload-field="' + fieldKey + '"]');
        var files = event.dataTransfer && event.dataTransfer.files;
        if (!input || !files || files.length === 0) { return; }
        try {
            input.files = files;
        } catch (error) {
            showFieldErrors({ [fieldKey]: ['此瀏覽器不支援拖曳檔案，請使用選擇檔案。'] });
            return;
        }
        void updateFileUploadMeta(input);
    });
    document.querySelectorAll('[data-field-type="constant_sum"][data-constant-sum-total]').forEach(updateConstantSumSummary);

    document.addEventListener('input', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.type === 'number') {
            var constantSumField = target.closest('[data-field-type="constant_sum"][data-constant-sum-total]');
            if (constantSumField) {
                updateConstantSumSummary(constantSumField);
            }
        }

        evaluateBranching();
        persistDraft();
    });
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest('[data-ranking-move]');
        if (!button) { return; }

        moveRankingItem(button, button.getAttribute('data-ranking-move'));
        persistDraft();
    });

    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (pageStack.length > 0) {
                currentPageKey = pageStack.pop();
                showPage(currentPageKey);
                persistDraft();
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
            persistDraft();
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
            recordSurveyEvent('started', { page_key: currentPageKey });
        });
    } else {
        recordSurveyEvent('started', { page_key: currentPageKey });
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
                lbl.classList.add('popping');
                setTimeout(function () { lbl.classList.remove('popping'); }, 180);
            });
        });

        // Restore state on page show (multi-page)
        updateFill(getCheckedIndex());
    });

    // ─── Linear scale value display ───────────────────────────────────────────
    document.querySelectorAll('[data-linear-scale-input]').forEach(function (input) {
        var wrap = input.closest('.survey-linear-scale');
        var valueLabel = wrap ? wrap.querySelector('[data-linear-scale-value]') : null;

        function updateValueLabel() {
            if (!valueLabel) return;
            valueLabel.textContent = input.value;
            var min = Number(input.min || 0);
            var max = Number(input.max || 100);
            var value = Number(input.value);
            var percent = max > min ? Math.min(100, Math.max(0, ((value - min) / (max - min)) * 100)) : 0;

            input.style.setProperty('--survey-range-fill', percent + '%');
        }

        input.addEventListener('input', updateValueLabel);
        updateValueLabel();
    });

    // ─── Per-field inline validation (blur) ──────────────────────────────────
    (function () {
        function clearFieldError(fieldKey) {
            var el = document.querySelector('.field-error[data-field="' + selectorEscape(fieldKey) + '"]');
            if (!el) { return; }
            el.textContent = '';
            if (isCdnMode()) { el.classList.add('hidden'); }
            else { el.classList.remove('visible'); }
        }

        document.addEventListener('focusout', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
                return;
            }
            if (target.disabled || target.type === 'file' || target.type === 'radio' || target.type === 'checkbox') {
                return;
            }

            var fieldEl = target.closest('[data-field-key]');
            if (!fieldEl) { return; }
            var fieldKey = fieldEl.getAttribute('data-field-key');

            if (fieldEl.getAttribute('data-field-type') === 'constant_sum') {
                var msg = validateConstantSumField(fieldEl);
                if (msg) { showFieldErrors({ [fieldKey]: [msg] }); }
                else { clearFieldError(fieldKey); }
                return;
            }

            if (!target.checkValidity()) {
                showFieldErrors({ [fieldKey]: [target.validationMessage] });
            } else {
                clearFieldError(fieldKey);
            }
        });
    }());

    // ─── 重複核選題（selection_based）動態選項 ──────────────────────────────────
    // 選項來自填答者在「來源題目」中所勾選的答案，於前端即時重建可複選清單。
    (function () {
        var selectionContainers = Array.prototype.slice.call(document.querySelectorAll('[data-selection-field]'));
        if (selectionContainers.length === 0) { return; }

        function sourceOptionLabel(input) {
            var label = input.closest('label');
            if (label) {
                var span = label.querySelector('span');
                if (span) { return span.textContent.trim(); }
            }
            return input.value;
        }

        function collectSourceSelections(sourceKey) {
            var selected = [];
            var select = document.querySelector('select[name="answers[' + sourceKey + ']"]');
            if (select) {
                if (select.value) {
                    selected.push({ value: select.value, label: (select.options[select.selectedIndex] || {}).textContent || select.value });
                }
                return selected;
            }
            var inputs = document.querySelectorAll('input[name="answers[' + sourceKey + ']"], input[name="answers[' + sourceKey + '][]"]');
            inputs.forEach(function (input) {
                if (input.checked) { selected.push({ value: input.value, label: sourceOptionLabel(input) }); }
            });
            return selected;
        }

        function rebuildSelection(container) {
            var fieldKey = container.getAttribute('data-selection-field');
            var sourceKey = container.getAttribute('data-selection-source');
            var isSurveyTheme = container.classList.contains('survey-choices');
            if (!sourceKey) { return; }

            var previouslyChecked = {};
            container.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) { previouslyChecked[cb.value] = true; });

            var options = collectSourceSelections(sourceKey);
            container.innerHTML = '';

            if (options.length === 0) {
                var empty = document.createElement('p');
                empty.setAttribute('data-selection-empty', '');
                empty.className = isSurveyTheme ? 'survey-help' : 'text-sm text-gray-400';
                empty.textContent = '請先回答來源題目，這裡會顯示可複選的選項。';
                container.appendChild(empty);
                return;
            }

            options.forEach(function (option) {
                var label = document.createElement('label');
                label.className = isSurveyTheme ? 'survey-choice-label' : 'flex items-center gap-2 cursor-pointer';
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'answers[' + fieldKey + '][]';
                input.value = option.value;
                input.className = isSurveyTheme ? 'survey-choice-input' : 'survey-choice-input h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500';
                if (previouslyChecked[option.value]) { input.checked = true; }
                var span = document.createElement('span');
                span.className = isSurveyTheme ? '' : 'text-sm text-gray-700';
                span.textContent = option.label;
                label.appendChild(input);
                label.appendChild(span);
                container.appendChild(label);
            });
        }

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || !target.name) { return; }
            var match = target.name.match(/^answers\[([^\]]+)\]/);
            if (!match) { return; }
            var changedKey = match[1];
            selectionContainers.forEach(function (container) {
                if (container.getAttribute('data-selection-source') === changedKey) { rebuildSelection(container); }
            });
        });

        selectionContainers.forEach(rebuildSelection);

        // 還原草稿時，來源題已於 restoreDraft() 先還原，故此時選項已依來源重建；
        // 這裡再把本題先前已勾選的選項補回（restoreDraft 當下選項尚未存在）。
        try {
            var draftRaw = window.localStorage.getItem(DRAFT_STORAGE_KEY);
            if (draftRaw) {
                var draftAnswers = (JSON.parse(draftRaw) || {}).answers || {};
                selectionContainers.forEach(function (container) {
                    var saved = draftAnswers[container.getAttribute('data-selection-field')];
                    if (!Array.isArray(saved)) { return; }
                    container.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                        if (saved.indexOf(checkbox.value) !== -1) { checkbox.checked = true; }
                    });
                });
            }
        } catch (e) { /* 忽略損壞的草稿資料 */ }
    }());

    // ─── Init ─────────────────────────────────────────────────────────────────
    evaluateBranching();
    if (IS_MULTI_PAGE && currentPageKey) { showPage(currentPageKey); }
}());
</script>
