<x-layouts.app title="Incidents">
    <x-page-header title="Incidents" icon="warning" subtitle="Downtime history across every monitor." />

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Open" :value="$stats['open']" icon="warning" />
        <x-stat label="Resolved" :value="$stats['resolved']" icon="check-circle" />
        <x-stat label="Unacknowledged" :value="$stats['unacknowledged']" icon="bell" />
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        <a href="{{ route('incidents.index') }}" @class(['px-3 py-1.5 rounded-lg font-medium', 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => ! $status, 'text-slate-600 hover:bg-slate-100' => $status])>All</a>
        <a href="{{ route('incidents.index', ['status' => 'open']) }}" @class(['px-3 py-1.5 rounded-lg font-medium', 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => $status === 'open', 'text-slate-600 hover:bg-slate-100' => $status !== 'open'])>Open</a>
        <a href="{{ route('incidents.index', ['status' => 'resolved']) }}" @class(['px-3 py-1.5 rounded-lg font-medium', 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => $status === 'resolved', 'text-slate-600 hover:bg-slate-100' => $status !== 'resolved'])>Resolved</a>
    </div>

    @if ($incidents->isEmpty())
        <x-card>
            <x-empty-state icon="warning" title="No Incidents" description="Downtime events will show up here as monitors report failures." />
        </x-card>
    @else
        <x-table>
            <thead>
                <tr><th>Monitor</th><th>Started</th><th>Resolved</th><th>Duration</th><th>Cause</th><th>Status</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($incidents as $i)
                    <tr>
                        <td><a href="{{ route('monitors.show', $i->monitor) }}" class="text-brand-700 hover:underline font-medium">{{ optional($i->monitor)->name ?? 'Unknown' }}</a></td>
                        <td class="text-slate-500">{{ $i->started_at->format('M j, Y g:i A') }}</td>
                        <td class="text-slate-500">{{ $i->resolved_at ? $i->resolved_at->format('M j, Y g:i A') : '—' }}</td>
                        <td class="tabular text-slate-500">{{ $i->duration_seconds ? gmdate('H:i:s', $i->duration_seconds) : ($i->isOpen() ? 'Ongoing' : '—') }}</td>
                        <td class="text-slate-500">{{ $i->cause ?: '—' }}</td>
                        <td>
                            @if ($i->isOpen())
                                <x-badge color="danger" dot>Open</x-badge>
                                @if ($i->isAcknowledged())<x-badge color="warn">Ack'd</x-badge>@endif
                            @else
                                <x-badge color="success">Resolved</x-badge>
                            @endif
                        </td>
                        <td class="text-right"><x-icon-button :href="route('incidents.show', $i)" icon="eye" title="Open" /></td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $incidents->links() }}</div>
    @endif
</x-layouts.app>
