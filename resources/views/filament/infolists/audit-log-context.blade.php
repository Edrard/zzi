@php
    $context = $getRecord()->context ?? [];
    
    if (empty($context)) {
        $isEmpty = true;
    } else {
        $isEmpty = false;
    }

    $formatValue = function ($value, $key = null) {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_string($value)) {
            if ($key === 'source') {
                return ucfirst($value);
            }
            if ($key === 'state_types') {
                return str_replace(['_', ','], [' ', ', '], $value);
            }
            return $value;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    $formatLabel = function ($key) {
        return ucfirst(str_replace('_', ' ', $key));
    };

    $isSimpleValue = function ($value) {
        return is_scalar($value) || is_null($value);
    };
@endphp

<div class="fi-in-text">
    @if($isEmpty)
        <span class="text-sm text-gray-500 dark:text-gray-400">No context</span>
    @else
        @if(isset($context['changes']) && is_array($context['changes']))
            <div class="text-sm">
                @foreach($context['changes'] as $change)
                    @php
                        $key = $change['key'] ?? '';
                        $old = is_string($change['old_value'] ?? null) ? ($change['old_value'] ?? 'null') : json_encode($change['old_value'] ?? 'null', JSON_UNESCAPED_UNICODE);
                        $new = is_string($change['new_value'] ?? null) ? ($change['new_value'] ?? 'null') : json_encode($change['new_value'] ?? 'null', JSON_UNESCAPED_UNICODE);
                    @endphp
                    <div>{{ $key }}: {{ $old }} &rarr; {{ $new }}</div>
                @endforeach
            </div>
        @else
            <div style="display: grid; grid-template-columns: 170px minmax(0, 1fr); column-gap: 12px; row-gap: 2px; font-size: 0.875rem; line-height: 1.35;">
                @foreach($context as $key => $value)
                    @if($isSimpleValue($value))
                        <div style="padding-left: 5px; color: rgb(156 163 175); font-weight: 500; text-align: left; white-space: nowrap;">
                            {{ $formatLabel($key) }}:
                        </div>
                        <div style="padding-left: 5px; text-align: left; overflow-wrap: anywhere; @if($key === 'error') color: rgb(220 38 38); @endif @if($key === 'source') font-weight: 600; @endif">
                            {{ $formatValue($value, $key) }}
                        </div>
                    @endif
                @endforeach
            </div>
                
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.875rem; margin-top: 8px;">
                @foreach($context as $key => $value)
                    @if(is_array($value) && $key === 'stats')
                        <div style="margin-top: 8px;">
                            <div style="margin-top: 10px; margin-bottom: 4px; padding-left: 5px; color: rgb(156 163 175); font-weight: 500;">
                                Stats
                            </div>
                            <div style="display: grid; grid-template-columns: 170px minmax(0, 1fr); column-gap: 12px; row-gap: 2px; font-size: 0.875rem; line-height: 1.35;">
                                @foreach($value as $statKey => $statValue)
                                    <div style="padding-left: 5px; color: rgb(156 163 175); font-weight: 500; text-align: left; white-space: nowrap;">
                                        {{ $formatLabel($statKey) }}:
                                    </div>
                                    <div style="padding-left: 5px; text-align: left; overflow-wrap: anywhere;">
                                        {{ $formatValue($statValue) }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @elseif(is_array($value) && $key === 'warnings')
                        <div style="margin-top: 8px;">
                            <div style="margin-top: 10px; margin-bottom: 4px; padding-left: 5px; color: rgb(156 163 175); font-weight: 500;">
                                Warnings:
                            </div>
                            @if(count($value) > 0)
                                <ul style="list-style-type: disc; list-style-position: inside; margin-top: 4px; padding-left: 5px;">
                                    @foreach($value as $warning)
                                        <li style="color: rgb(202 138 4);">{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <span style="color: rgb(156 163 175); margin-left: 8px;">0</span>
                            @endif
                        </div>
                    @elseif(is_array($value) && $key !== 'changes' && $key !== 'stats' && $key !== 'warnings')
                        <div style="margin-top: 8px;">
                            <div style="margin-top: 10px; margin-bottom: 4px; padding-left: 5px; color: rgb(156 163 175); font-weight: 500;">
                                {{ $formatLabel($key) }}:
                            </div>
                            <pre style="font-size: 0.75rem; overflow-x: auto; white-space: pre-wrap; margin-top: 4px; padding-left: 5px;">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                @endforeach
            </div>

            <details style="margin-top: 16px;">
                <summary style="cursor: pointer; font-size: 0.75rem; color: rgb(156 163 175);">Raw context</summary>
                <div style="margin-top: 8px;">
                    <pre style="font-size: 0.75rem; overflow-x: auto; white-space: pre-wrap; padding: 12px; background-color: rgba(0, 0, 0, 0.05); border-radius: 8px;">{{ json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </details>
        @endif
    @endif
</div>
