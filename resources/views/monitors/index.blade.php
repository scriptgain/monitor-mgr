<x-layouts.app title="Monitors">
    <x-page-header title="Monitors" icon="pulse" subtitle="Uptime, ping, TCP, keyword, SSL, DNS, heartbeat, and server-agent checks.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('monitors.create') }}">New Monitor</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Up" :value="$stats['up']" icon="check-circle" />
        <x-stat label="Down" :value="$stats['down']" icon="x-circle" />
        <x-stat label="Paused" :value="$stats['paused']" icon="clock" />
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        <a href="{{ route('monitors.index') }}" @class(['px-3 py-1.5 rounded-lg font-medium', 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => ! $status, 'text-slate-600 hover:bg-slate-100' => $status])>All</a>
        @foreach (\App\Models\Monitor::STATUSES as $sc => $sl)
            <a href="{{ route('monitors.index', ['status' => $sc]) }}" @class(['px-3 py-1.5 rounded-lg font-medium', 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => $status === $sc, 'text-slate-600 hover:bg-slate-100' => $status !== $sc])>{{ $sl }}</a>
        @endforeach
    </div>

    @if ($monitors->isEmpty())
        <x-card>
            <x-empty-state icon="pulse" title="No Monitors Here" description="Add your first uptime, server, or agent monitor.">
                <x-slot:action><x-button icon="plus" href="{{ route('monitors.create') }}">New Monitor</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Type</th><th>Target</th><th>Status</th><th>Uptime</th><th>Last Checked</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($monitors as $m)
                    <tr>
                        <td><a href="{{ route('monitors.show', $m) }}" class="text-brand-700 hover:underline font-medium">{{ $m->name }}</a></td>
                        @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $m->owner?->name ?? 'Unassigned' }}</td>@endif
                        <td class="text-slate-600">{{ $m->typeLabel() }}</td>
                        <td class="text-slate-500 font-mono text-xs">{{ $m->target }}{{ $m->port ? ':' . $m->port : '' }}</td>
                        <td>
                            <x-badge :color="['up' => 'success', 'down' => 'danger', 'paused' => 'neutral'][$m->status] ?? 'neutral'" dot>
                                {{ $m->statusLabel() }}
                                @if ($m->open_incidents_count) &middot; {{ $m->open_incidents_count }} open @endif
                            </x-badge>
                        </td>
                        <td class="tabular text-slate-500">{{ number_format($m->uptime_ratio, 1) }}%</td>
                        <td class="text-slate-500">{{ optional($m->last_checked_at)->diffForHumans() ?? 'Never' }}</td>
                        <td class="text-right"><x-icon-button :href="route('monitors.show', $m)" icon="eye" title="Open" /></td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $monitors->links() }}</div>
    @endif
</x-layouts.app>
