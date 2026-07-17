<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $statusPage->name }} — Status</title>
    <x-tailwind-cdn />
</head>
<body class="min-h-full text-slate-800">
    @php
        $monitors = $statusPage->monitors;
        $anyDown = $monitors->contains(fn ($m) => $m->status === 'down');
        $allUp = $monitors->isNotEmpty() && $monitors->every(fn ($m) => $m->status === 'up');
        $banner = $anyDown
            ? ['Some Systems Are Down', 'bg-rose-50 ring-rose-200 text-rose-800']
            : ($allUp ? ['All Systems Operational', 'bg-emerald-50 ring-emerald-200 text-emerald-800']
                      : ['Partial Service Disruption', 'bg-amber-50 ring-amber-200 text-amber-800']);
    @endphp
    <div class="max-w-3xl mx-auto px-4 py-12">
        <header class="mb-8">
            <h1 class="text-2xl font-semibold text-slate-900">{{ $statusPage->name }}</h1>
            @if ($statusPage->description)
                <p class="mt-2 text-slate-600">{{ $statusPage->description }}</p>
            @endif
        </header>

        <div class="mb-6 rounded-xl px-5 py-4 ring-1 {{ $banner[1] }}">
            <p class="font-medium">{{ $banner[0] }}</p>
        </div>

        <div class="rounded-xl ring-1 ring-slate-200 bg-white divide-y divide-slate-100 shadow-sm">
            @forelse ($monitors as $m)
                @php
                    $s = ['up' => ['Operational', 'bg-emerald-100 text-emerald-700'], 'down' => ['Down', 'bg-rose-100 text-rose-700'], 'paused' => ['Paused', 'bg-slate-100 text-slate-600']][$m->status] ?? [ucfirst($m->status), 'bg-slate-100 text-slate-600'];
                @endphp
                <div class="flex items-center justify-between gap-4 px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900 truncate">{{ $m->name }}</p>
                        <p class="text-xs text-slate-500">{{ number_format($m->uptime_ratio, 1) }}% uptime</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $s[1] }}">{{ $s[0] }}</span>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-slate-500">No monitors on this page yet.</p>
            @endforelse
        </div>

        <footer class="mt-8 text-center text-xs text-slate-400">Powered by {{ config('app.name') }}</footer>
    </div>
</body>
</html>
