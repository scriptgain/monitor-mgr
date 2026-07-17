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
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $incidents->pluck('id')->implode(',') }}],
                toggleAll(e) { this.selected = e.target.checked ? [...this.allIds] : []; this.confirming = false; },
                submitBulk() {
                    const f = this.$refs.bulkForm;
                    f.querySelectorAll('input.js-dyn').forEach(n => n.remove());
                    this.selected.forEach(id => {
                        const i = document.createElement('input');
                        i.type = 'hidden'; i.name = 'ids[]'; i.value = id; i.className = 'js-dyn';
                        f.appendChild(i);
                    });
                    f.submit();
                }
            }">
            {{-- Hidden form the bulk delete posts through. --}}
            <form method="POST" action="{{ route('incidents.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one incident is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> incident(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>

            <x-table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <button type="button" role="switch"
                                :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                :disabled="allIds.length === 0" aria-label="Select all incidents">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Monitor</th><th>Started</th><th>Resolved</th><th>Duration</th><th>Cause</th><th>Status</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($incidents as $i)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $i->id }}).toString()"
                                    @click="selected.includes({{ $i->id }}) ? selected.splice(selected.indexOf({{ $i->id }}), 1) : selected.push({{ $i->id }}); confirming = false"
                                    :class="selected.includes({{ $i->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select incident">
                                    <span :class="selected.includes({{ $i->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
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
        </div>
        <div class="mt-4">{{ $incidents->links() }}</div>
    @endif
</x-layouts.app>
