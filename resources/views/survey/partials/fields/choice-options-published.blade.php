{{-- 單選/複選共用選項清單（published CSS layout）；$isMultiple 決定 input 類型與 name 格式 --}}
<div class="survey-choices" @unless($isMultiple) data-jump-field="{{ $fk }}" @endunless>
    @php $lastGroup = null; @endphp
    @foreach($field->displayOptions($shuffleSeed ?? null) as $option)
        @continue($option['is_hidden'])
        @php $optGroup = $option['group'] ?? null; @endphp
        @if($optGroup !== $lastGroup)
            @php $lastGroup = $optGroup; @endphp
            @if($optGroup)<p style="margin-top:.5rem;font-size:.75rem;font-weight:600;color:var(--survey-text-muted);">{{ $optGroup }}</p>@endif
        @endif
        @php
            $used = $optionUsage[$fk][$option['value']] ?? 0;
            $isFull = $option['capacity'] !== null && $used >= $option['capacity'];
        @endphp
        <label class="survey-choice-label" @if($isFull) style="opacity:.6" @endif>
            <input class="survey-choice-input" type="{{ $isMultiple ? 'checkbox' : 'radio' }}" name="answers[{{ $fk }}]{{ $isMultiple ? '[]' : '' }}" value="{{ $option['value'] }}"
                @if(! $isMultiple && $field->is_required) required @endif
                @if($isFull) disabled @endif>
            <span>{{ $option['label'] }}@if($isFull)（已額滿）@endif</span>
        </label>
    @endforeach
</div>
