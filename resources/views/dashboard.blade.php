@php
    use Illuminate\Support\Str;

    // KPI row — number + label + a meaningful one-line subtext, grouped with the icon.
    $uptimeVal = $overallUptime !== null ? number_format($overallUptime, 2) : '100.00';
    $kpis = [
        ['label' => 'Monitors', 'value' => number_format($stats['monitors']), 'icon' => 'pulse',
            'sub' => $stats['paused'] ? $stats['paused'] . ' ' . Str::plural('monitor', $stats['paused']) . ' paused' : 'All monitors active',
            'tone' => 'muted'],
        ['label' => 'Up', 'value' => number_format($stats['up']), 'icon' => 'check-circle',
            'sub' => $uptimeVal . '% overall uptime', 'tone' => 'emerald'],
        ['label' => 'Down', 'value' => number_format($stats['down']), 'icon' => 'x-circle',
            'sub' => $stats['down'] ? 'Needs attention now' : 'Everything is healthy',
            'tone' => $stats['down'] ? 'rose' : 'emerald'],
        ['label' => 'Open Incidents', 'value' => number_format($openIncidents), 'icon' => 'warning',
            'sub' => $openIncidents ? 'Currently unresolved' : 'No active incidents',
            'tone' => $openIncidents ? 'amber' : 'muted'],
    ];
    $toneClass = ['muted' => 'text-slate-400', 'amber' => 'text-amber-600', 'emerald' => 'text-emerald-600', 'rose' => 'text-rose-600'];

    // 14-day check activity bar chart geometry (inline SVG, no chart library).
    $cw = 700; $ch = 150; $padT = 12; $padB = 22;
    $plotH = $ch - $padT - $padB;
    $baseY = $padT + $plotH;
    $n = max(1, count($activity));
    $slot = ($cw - 8) / $n;
    $barW = min(26, $slot * 0.62);
    $maxVal = max(1, max(array_column($activity, 'total') ?: [0]));

    // Uptime gauge (semicircle) geometry.
    $gaugeLen = 276.46; // ~ pi * r, r = 88
    $uptimePct = $overallUptime !== null ? max(0, min(100, (float) $overallUptime)) : 100;
    $uptimeLow = $uptimePct < 99;
    $gaugeDash = round($uptimePct / 100 * $gaugeLen, 1);
@endphp

<x-layouts.app title="Dashboard">
    {{-- Brand accent bound to the runtime --color-brand-* var so a custom accent still applies. --}}
    <style>
        .mk-ok-fill { fill: var(--color-brand-500); }
        .mk-ok-stroke { stroke: var(--color-brand-500); }
        .mk-ok-bg { background-color: var(--color-brand-500); }
    </style>

    <x-page-header title="Dashboard" subtitle="Uptime, incidents, and response times at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="warning" href="{{ route('incidents.index') }}">Incidents</x-button>
            <x-button size="sm" icon="plus" href="{{ route('monitors.create') }}">New Monitor</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- KPI row --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ($kpis as $k)
            <div class="group relative flex flex-col overflow-hidden rounded-xl bg-white ring-1 ring-slate-200 shadow-sm transition hover:shadow-md hover:ring-brand-200">
                <span class="h-1 w-full bg-gradient-to-r from-brand-400 to-brand-600"></span>
                <div class="flex flex-1 items-center gap-4 p-5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                        <x-icon :name="$k['icon']" class="h-5 w-5" />
                    </span>
                    <div class="ml-auto text-right">
                        <p class="text-2xl font-semibold tracking-tight text-slate-900 tabular">{{ $k['value'] }}</p>
                        <p class="mt-0.5 text-sm font-medium text-slate-600">{{ $k['label'] }}</p>
                        <p class="mt-0.5 text-xs font-medium {{ $toneClass[$k['tone']] }}">{{ $k['sub'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Check activity + uptime gauge --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {{-- Check activity (signature visual) --}}
        <x-card title="Check Activity" subtitle="Checks per day, last 14 days" class="lg:col-span-2">
            <x-slot:actions>
                @if ($successRate !== null)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                        <x-icon name="check-circle" class="h-3.5 w-3.5" /> {{ $successRate }}% up
                    </span>
                @endif
            </x-slot:actions>

            @if ($windowTotal === 0)
                <div class="flex h-40 flex-col items-center justify-center text-center">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="pulse" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm text-slate-500">No checks recorded in the last 14 days.</p>
                </div>
            @else
                <svg viewBox="0 0 {{ $cw }} {{ $ch }}" width="100%" class="block h-auto" role="img" aria-label="Checks per day over the last 14 days">
                    <line x1="4" y1="{{ $baseY + 0.5 }}" x2="{{ $cw - 4 }}" y2="{{ $baseY + 0.5 }}" stroke="#e2e8f0" stroke-width="1" />
                    @foreach ($activity as $i => $d)
                        @php
                            $cx = 4 + $slot * $i + $slot / 2;
                            $x = round($cx - $barW / 2, 1);
                            $h = $d['total'] ? max(3, round($d['total'] / $maxVal * $plotH, 1)) : 0;
                            $dh = $d['total'] ? round($d['down'] / $d['total'] * $h, 1) : 0;
                            $uh = round($h - $dh, 1);
                        @endphp
                        @if ($h === 0.0 || $h === 0)
                            <rect x="{{ $x }}" y="{{ $baseY - 3 }}" width="{{ round($barW, 1) }}" height="3" rx="1.5" fill="#e2e8f0" />
                        @else
                            @if ($uh > 0)
                                <rect x="{{ $x }}" y="{{ round($baseY - $h, 1) }}" width="{{ round($barW, 1) }}" height="{{ $uh }}" rx="2" class="mk-ok-fill" />
                            @endif
                            @if ($dh > 0)
                                <rect x="{{ $x }}" y="{{ round($baseY - $dh, 1) }}" width="{{ round($barW, 1) }}" height="{{ $dh }}" rx="2" fill="#f43f5e" />
                            @endif
                        @endif
                        @if ($i === 0 || $i === intdiv($n, 2) || $i === $n - 1)
                            <text x="{{ round($cx, 1) }}" y="{{ $ch - 6 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}" fill="#94a3b8" style="font-size:11px">{{ $d['label'] }}</text>
                        @endif
                    @endforeach
                </svg>

                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm mk-ok-bg"></span> Up</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color:#f43f5e"></span> Down</span>
                    <span class="ml-auto tabular text-slate-400">{{ number_format($windowTotal) }} {{ Str::plural('check', $windowTotal) }} total</span>
                </div>
            @endif

            <x-slot:footer>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="clock" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular text-slate-900">{{ $avgResponseMs !== null ? number_format($avgResponseMs) . ' ms' : '—' }}</p>
                            <p class="text-xs text-slate-500">Avg response · 24h</p>
                        </div>
                    </div>
                    <span class="h-9 w-px bg-slate-200"></span>
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg {{ $stats['down'] ? 'bg-rose-50 text-rose-600 ring-1 ring-rose-100' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}"><x-icon name="x-circle" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular {{ $stats['down'] ? 'text-rose-600' : 'text-slate-900' }}">{{ $stats['down'] }}</p>
                            <p class="text-xs text-slate-500">Monitors down now</p>
                        </div>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>

        {{-- Uptime gauge --}}
        <x-card title="Overall Uptime" subtitle="Across active monitors">
            <div>
                <div class="mx-auto w-full max-w-[240px]">
                    <svg viewBox="0 0 200 122" width="100%" role="img" aria-label="Overall uptime {{ $uptimeVal }} percent">
                        <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke="#e2e8f0" stroke-width="14" stroke-linecap="round" />
                        <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke-width="14" stroke-linecap="round"
                            stroke-dasharray="{{ $gaugeDash }} 1000"
                            @class(['mk-ok-stroke' => ! $uptimeLow]) @style(['stroke:#f59e0b' => $uptimeLow]) />
                        <text x="100" y="92" text-anchor="middle" fill="#0f172a" style="font-size:32px;font-weight:700;font-variant-numeric:tabular-nums">{{ $uptimeVal }}%</text>
                        <text x="100" y="110" text-anchor="middle" fill="#94a3b8" style="font-size:11px;letter-spacing:.02em">uptime</text>
                    </svg>
                </div>
                <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-slate-50 py-2 ring-1 ring-slate-100">
                        <p class="text-lg font-semibold tabular text-emerald-600">{{ $stats['up'] }}</p>
                        <p class="text-xs text-slate-500">Up</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 py-2 ring-1 ring-slate-100">
                        <p class="text-lg font-semibold tabular {{ $stats['down'] ? 'text-rose-600' : 'text-slate-400' }}">{{ $stats['down'] }}</p>
                        <p class="text-xs text-slate-500">Down</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 py-2 ring-1 ring-slate-100">
                        <p class="text-lg font-semibold tabular text-slate-500">{{ $stats['paused'] }}</p>
                        <p class="text-xs text-slate-500">Paused</p>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Down monitors + recent incidents --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <div class="lg:col-span-2">
            <x-card title="Down Monitors" subtitle="Endpoints currently reporting down" :flush="! $downMonitors->isEmpty()">
                <x-slot:actions>
                    <a href="{{ route('monitors.index') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800">View All</a>
                </x-slot:actions>
                @if ($downMonitors->isEmpty())
                    <x-empty-state icon="check-circle" title="Everything Is Up" description="No monitors are currently reporting down." />
                @else
                    <x-table flush>
                        <thead><tr><th>Name</th><th>Type</th><th>Status</th><th class="text-right">Last Checked</th></tr></thead>
                        <tbody>
                            @foreach ($downMonitors as $m)
                                <tr class="cursor-pointer" onclick="window.location='{{ route('monitors.show', $m) }}'">
                                    <td>
                                        <div class="font-medium text-slate-900 truncate">{{ $m->name }}</div>
                                        <div class="text-xs text-slate-500 font-mono truncate">{{ $m->target }}</div>
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">{{ $m->typeLabel() }}</span>
                                    </td>
                                    <td><x-badge color="danger" dot>Down</x-badge></td>
                                    <td class="text-right text-slate-500" data-tip="{{ optional($m->last_checked_at)?->format('M j, Y g:i A') }}">{{ optional($m->last_checked_at)->diffForHumans() ?? 'Never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>
        </div>

        <div>
            <x-card title="Recent Incidents" subtitle="Latest outages">
                @if ($recentIncidents->isEmpty())
                    <x-empty-state icon="warning" title="No Incidents" description="Nothing has gone down yet." />
                @else
                    <ul class="-my-1 divide-y divide-slate-100">
                        @foreach ($recentIncidents as $i)
                            <li class="flex items-center justify-between gap-3 py-3">
                                <a href="{{ route('incidents.show', $i) }}" class="flex min-w-0 items-center gap-2.5">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $i->isOpen() ? 'bg-rose-50 text-rose-600 ring-1 ring-rose-100' : 'bg-slate-50 text-slate-400 ring-1 ring-slate-100' }}">
                                        <x-icon name="warning" class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900 hover:text-brand-700">{{ optional($i->monitor)->name ?? 'Unknown monitor' }}</p>
                                        <p class="text-xs text-slate-400" data-tip="{{ $i->started_at?->format('M j, Y g:i A') }}">{{ $i->started_at->diffForHumans() }}</p>
                                    </span>
                                </a>
                                @if ($i->isOpen())
                                    <x-badge color="danger" dot>Open</x-badge>
                                @else
                                    <x-badge color="success">Resolved</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
