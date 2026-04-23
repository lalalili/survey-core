<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $survey->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen py-10">
<div class="max-w-2xl mx-auto px-4">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">{{ $survey->title }}</h1>
        @if($survey->description)
            <p class="mt-2 text-gray-600 whitespace-pre-line">{{ $survey->description }}</p>
        @endif
    </div>

    {{-- Success message --}}
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

    {{-- Survey Form --}}
    <form id="survey-form" class="space-y-6">
        @csrf

        @foreach($survey->fields->where('is_hidden', false)->sortBy('sort_order') as $field)
            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <label class="block text-sm font-medium text-gray-900 mb-1">
                    {{ $field->label }}
                    @if($field->is_required)
                        <span class="text-red-500 ml-0.5">*</span>
                    @endif
                </label>

                @if($field->description)
                    <p class="text-xs text-gray-500 mb-2">{{ $field->description }}</p>
                @endif

                @php $fk = $field->field_key; $type = $field->type->value; @endphp

                @if($type === 'short_text' || $type === 'email' || $type === 'phone')
                    <input
                        type="{{ $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text') }}"
                        name="answers[{{ $fk }}]"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border"
                    >

                @elseif($type === 'long_text')
                    <textarea
                        name="answers[{{ $fk }}]"
                        rows="4"
                        placeholder="{{ $field->placeholder ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border"
                    >{{ $field->default_value ?? '' }}</textarea>

                @elseif($type === 'single_choice')
                    <div class="space-y-2 mt-1">
                        @foreach($field->options_json ?? [] as $value => $label)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="answers[{{ $fk }}]" value="{{ $value }}"
                                    @if($field->is_required) required @endif
                                    class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'multiple_choice')
                    <div class="space-y-2 mt-1">
                        @foreach($field->options_json ?? [] as $value => $label)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="answers[{{ $fk }}][]" value="{{ $value }}"
                                    class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($type === 'select')
                    <select
                        name="answers[{{ $fk }}]"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border"
                    >
                        <option value="">請選擇</option>
                        @foreach($field->options_json ?? [] as $value => $label)
                            <option value="{{ $value }}" @if($field->default_value === $value) selected @endif>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>

                @elseif($type === 'rating')
                    <div class="flex gap-3 mt-1">
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
                    <input
                        type="date"
                        name="answers[{{ $fk }}]"
                        value="{{ $field->default_value ?? '' }}"
                        @if($field->is_required) required @endif
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm px-3 py-2 border"
                    >
                @endif

                <p class="text-xs text-red-500 mt-1 hidden field-error" data-field="{{ $fk }}"></p>
            </div>
        @endforeach

        <div class="flex justify-end pt-2">
            <button
                type="submit"
                id="submit-btn"
                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-60"
            >
                <span id="submit-label">送出問卷</span>
                <svg id="submit-spinner" class="hidden animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('survey-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('submit-btn');
    const spinner = document.getElementById('submit-spinner');
    const label = document.getElementById('submit-label');
    const errorBanner = document.getElementById('error-banner');
    const errorText = document.getElementById('error-text');

    // Clear previous errors
    document.querySelectorAll('.field-error').forEach(el => {
        el.textContent = '';
        el.classList.add('hidden');
    });
    errorBanner.classList.add('hidden');

    btn.disabled = true;
    spinner.classList.remove('hidden');
    label.textContent = '送出中…';

    const formData = new FormData(this);
    const answers = {};

    for (const [key, value] of formData.entries()) {
        const match = key.match(/^answers\[([^\]]+)\](\[\])?$/);
        if (!match) continue;
        const fieldKey = match[1];
        const isArray = !!match[2];
        if (isArray) {
            if (!answers[fieldKey]) answers[fieldKey] = [];
            answers[fieldKey].push(value);
        } else {
            answers[fieldKey] = value;
        }
    }

    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const url = '{{ route("survey.submit", $survey->public_key) }}' + '{{ request()->has("t") ? "?t=" . request()->query("t") : "" }}';

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ answers }),
        });

        const data = await res.json();

        if (res.ok) {
            document.getElementById('survey-form').classList.add('hidden');
            const successEl = document.getElementById('success-message');
            successEl.classList.remove('hidden');
            if (data.message) {
                document.getElementById('success-text').textContent = data.message;
            }
        } else if (res.status === 422 && data.errors) {
            for (const [field, messages] of Object.entries(data.errors)) {
                const errorEl = document.querySelector(`.field-error[data-field="${field}"]`);
                if (errorEl) {
                    errorEl.textContent = Array.isArray(messages) ? messages[0] : messages;
                    errorEl.classList.remove('hidden');
                }
            }
            btn.disabled = false;
            spinner.classList.add('hidden');
            label.textContent = '送出問卷';
        } else {
            errorText.textContent = data.message ?? '送出失敗，請稍後再試。';
            errorBanner.classList.remove('hidden');
            btn.disabled = false;
            spinner.classList.add('hidden');
            label.textContent = '送出問卷';
        }
    } catch {
        errorText.textContent = '網路錯誤，請稍後再試。';
        errorBanner.classList.remove('hidden');
        btn.disabled = false;
        spinner.classList.add('hidden');
        label.textContent = '送出問卷';
    }
});
</script>
</body>
</html>
