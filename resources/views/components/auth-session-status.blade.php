@props([
    'status',
])

@if ($status)
    {{-- emerald-700/-400, not the --color-success token (emerald-600/-400): 600
    only clears the 3:1 non-text contrast floor, not the 4.5:1 small-text floor
    this status line needs. --}}
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-emerald-700 dark:text-emerald-400']) }}>
        {{ $status }}
    </div>
@endif
