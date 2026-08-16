<x-layouts.app title="Triggers">
    <x-page-header title="Triggers" icon="bolt" subtitle="Thresholds over host metrics. A breach opens an incident and alerts your contacts.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('triggers.create') }}">New Trigger</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($triggers->isEmpty())
        <x-card>
            <x-empty-state icon="bolt" title="No Triggers Yet" description="Without a trigger, metrics are recorded and nobody is told. Add a rule such as disk above 90 percent.">
                <x-slot:action><x-button icon="plus" href="{{ route('triggers.create') }}">New Trigger</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div x-data="{ selected: [], confirming: false, allIds: [{{ $triggers->pluck('id')->implode(',') }}], submitBulk(action) { const f = this.$refs.bulkForm; f.querySelectorAll('input.js-dyn').forEach(n => n.remove()); this.selected.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; i.className='js-dyn'; f.appendChild(i); }); f.querySelector('input[name=action]').value = action; f.submit(); } }"
             class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <form method="POST" action="{{ route('triggers.bulk') }}" x-ref="bulkForm" class="hidden">@csrf<input type="hidden" name="action" value="delete"></form>
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-brand-50 px-4 py-2.5">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-button type="button" variant="secondary" size="sm" x-on:click="submitBulk('enable')">Enable</x-button>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="submitBulk('disable')">Disable</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                        </div>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> trigger(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk('delete')">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>
            <x-table flush>
                <thead><tr>
                    <th class="w-10"><x-select-all /></th>
                    <th>Name</th>
                    <th>Applies To</th>
                    <th>Condition</th>
                    <th>Sustained</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr></thead>
                <tbody>
                    @foreach ($triggers as $t)
                        <tr>
                            <td><x-select-one :id="$t->id" /></td>
                            <td class="font-medium text-slate-900">
                                {{ $t->name }}
                                @if ($t->open_incidents_count)
                                    <a href="{{ route('incidents.index', ['status' => 'open']) }}" class="ml-1 text-xs text-red-600 hover:underline">{{ $t->open_incidents_count }} open</a>
                                @endif
                            </td>
                            <td class="text-slate-600">{{ $t->host?->name ?? 'Every host' }}</td>
                            <td class="text-slate-600">{{ $t->condition() }}</td>
                            <td class="text-slate-500 tabular">{{ $t->for_seconds ? $t->for_seconds . 's' : 'Immediately' }}</td>
                            <td><x-badge :color="['info' => 'neutral', 'warning' => 'warning', 'average' => 'warning', 'high' => 'danger', 'disaster' => 'danger'][$t->severity] ?? 'neutral'">{{ $t->severityLabel() }}</x-badge></td>
                            <td>@if($t->is_enabled)<x-badge color="success" dot>Enabled</x-badge>@else<x-badge color="neutral">Disabled</x-badge>@endif</td>
                            <td class="text-right">
                                <x-icon-button :href="route('triggers.edit', $t)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-trigger-' . $t->id" :action="route('triggers.destroy', $t)"
                                    title="Delete Trigger?" :message="'Remove ' . $t->name . '? Any incident it opened stays in the history.'" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $triggers->links() }}</div>
    @endif
</x-layouts.app>
