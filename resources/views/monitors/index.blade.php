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
        <div x-data="{ selected: [], confirming: false, allIds: [{{ $monitors->pluck('id')->implode(',') }}], run(action) { const f = this.$refs.bulkForm; f.querySelectorAll('input.js-dyn').forEach(n => n.remove()); const a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value=action; a.className='js-dyn'; f.appendChild(a); this.selected.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; i.className='js-dyn'; f.appendChild(i); }); f.submit(); } }"
             class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <form method="POST" action="{{ route('monitors.bulk') }}" x-ref="bulkForm" class="hidden">@csrf</form>
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-brand-50 px-4 py-2.5">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button type="button" variant="secondary" size="sm" icon="pause" x-on:click="run('pause')">Pause</x-button>
                    <x-button type="button" variant="secondary" size="sm" icon="play" x-on:click="run('resume')">Resume</x-button>
                    <template x-if="! confirming"><x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete</x-button></template>
                    <template x-if="confirming">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> monitor(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="run('delete')">Confirm Delete</x-button>
                        </span>
                    </template>
                </div>
            </div>
        <x-table flush>
            <thead>
                <tr><th class="w-10"><x-select-all /></th><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Type</th><th>Target</th><th>Status</th><th>Uptime</th><th>Last Checked</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($monitors as $m)
                    <tr>
                        <td><x-select-one :id="$m->id" /></td>
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
        </div>
        <div class="mt-4">{{ $monitors->links() }}</div>
    @endif
</x-layouts.app>
