@props(['pct' => 0, 'label' => null])
@php
    $p = max(0, min(100, (float) $pct));
    // Color ramps by pressure: calm under 70, amber to 90, red above.
    $color = $p >= 90 ? 'bg-rose-500' : ($p >= 70 ? 'bg-amber-500' : 'bg-emerald-500');
@endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-2 min-w-[7rem]']) }}>
    <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
        <div class="h-full rounded-full {{ $color }}" style="width: {{ $p }}%"></div>
    </div>
    <span class="text-xs tabular text-slate-600 w-10 text-right">{{ number_format($p, 0) }}%</span>
</div>
