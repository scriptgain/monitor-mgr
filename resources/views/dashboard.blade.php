<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" subtitle="Uptime, incidents, and response times at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="warning" href="{{ route('incidents.index') }}">Incidents</x-button>
            <x-button size="sm" icon="plus" href="{{ route('monitors.create') }}">New Monitor</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="Monitors Up" :value="number_format($stats['up'])" icon="check-circle" />
        <x-stat label="Monitors Down" :value="number_format($stats['down'])" icon="x-circle" />
        <x-stat label="Paused" :value="number_format($stats['paused'])" icon="clock" />
        <x-stat label="Overall Uptime" :value="($overallUptime !== null ? number_format($overallUptime, 2) : '100.00') . '%'" icon="pulse" />
    </div>

    {{-- Health strip --}}
    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">Open Incidents</p>
                    <p class="mt-1 text-2xl font-semibold tabular {{ $openIncidents ? 'text-rose-600' : 'text-slate-900' }}">{{ $openIncidents }}</p>
                </div>
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg ring-1 {{ $openIncidents ? 'bg-rose-50 text-rose-600 ring-rose-100' : 'bg-slate-50 text-slate-400 ring-slate-100' }}"><x-icon name="warning" class="w-5 h-5" /></span>
            </div>
        </x-card>
        <x-card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">Avg Response (24h)</p>
                    <p class="mt-1 text-2xl font-semibold tabular text-slate-900">{{ $avgResponseMs !== null ? number_format($avgResponseMs) . ' ms' : '—' }}</p>
                </div>
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg ring-1 bg-brand-50 text-brand-600 ring-brand-100"><x-icon name="clock" class="w-5 h-5" /></span>
            </div>
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Down monitors --}}
        <div class="lg:col-span-2">
            <x-card title="Down Monitors">
                @if ($downMonitors->isEmpty())
                    <x-empty-state icon="check-circle" title="Everything Is Up" description="No monitors are currently reporting down." />
                @else
                    <x-table>
                        <thead><tr><th>Name</th><th>Type</th><th>Target</th><th>Last Checked</th></tr></thead>
                        <tbody>
                            @foreach ($downMonitors as $m)
                                <tr>
                                    <td><a href="{{ route('monitors.show', $m) }}" class="text-brand-700 hover:underline font-medium">{{ $m->name }}</a></td>
                                    <td class="text-slate-600">{{ $m->typeLabel() }}</td>
                                    <td class="text-slate-500 font-mono text-xs">{{ $m->target }}</td>
                                    <td class="text-slate-500">{{ optional($m->last_checked_at)->diffForHumans() ?? 'Never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>
        </div>

        {{-- Recent incidents --}}
        <div>
            <x-card title="Recent Incidents">
                @if ($recentIncidents->isEmpty())
                    <x-empty-state icon="warning" title="No Incidents" description="Nothing has gone down yet." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($recentIncidents as $i)
                            <li class="py-2.5 flex items-center justify-between gap-2">
                                <a href="{{ route('incidents.show', $i) }}" class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900 truncate hover:text-brand-700">{{ optional($i->monitor)->name ?? 'Unknown monitor' }}</p>
                                    <p class="text-xs text-slate-400">{{ $i->started_at->diffForHumans() }}</p>
                                </a>
                                @if ($i->isOpen())<x-badge color="danger" dot>Open</x-badge>
                                @else<x-badge color="success">Resolved</x-badge>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
