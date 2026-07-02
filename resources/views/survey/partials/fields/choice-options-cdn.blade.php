{{-- 單選/複選共用選項清單（Tailwind CDN layout）；$isMultiple 決定 input 類型與 name 格式 --}}
<div class="space-y-2 mt-1" @unless($isMultiple) data-jump-field="{{ $fk }}" @endunless>
    @php $lastGroup = null; @endphp
    @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
        @continue($option['is_hidden'])
        @php $optGroup = $option['group'] ?? null; @endphp
        @if($optGroup !== $lastGroup)
            @php $lastGroup = $optGroup; @endphp
            @if($optGroup)<p class="w-full text-xs font-semibold text-gray-500 mt-2">{{ $optGroup }}</p>@endif
        @endif
        @php
            $used = $optionUsage[$fk][$option['value']] ?? 0;
            $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
        @endphp
        <label class="flex items-center gap-2 {{ $isFull ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
            <input type="{{ $isMultiple ? 'checkbox' : 'radio' }}" name="answers[{{ $fk }}]{{ $isMultiple ? '[]' : '' }}" value="{{ $option['value'] }}"
                @if(! $isMultiple && $field->is_required) required @endif
                @if($isFull) disabled @endif
                class="survey-choice-input h-4 w-4 @if($isMultiple)rounded @endif border-gray-300 text-indigo-600 focus:ring-indigo-500">
            <span class="text-sm text-gray-700">{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
        </label>
    @endforeach
</div>
