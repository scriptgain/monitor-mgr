<x-layouts.app title="Alerts">
    <x-page-header title="Alerts" icon="bell" subtitle="Where notifications go when a monitor changes state.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('alerts.create') }}">New Alert Contact</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($alertContacts->isEmpty())
        <x-card>
            <x-empty-state icon="bell" title="No Alert Contacts Yet" description="Add an email, webhook, SMS, or Slack destination.">
                <x-slot:action><x-button icon="plus" href="{{ route('alerts.create') }}">New Alert Contact</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div x-data="{ selected: [], confirming: false, allIds: [{{ $alertContacts->pluck('id')->implode(',') }}], submitBulk() { const f = this.$refs.bulkForm; f.querySelectorAll('input.js-dyn').forEach(n => n.remove()); this.selected.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; i.className='js-dyn'; f.appendChild(i); }); f.submit(); } }"
             class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <form method="POST" action="{{ route('alerts.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-brand-50 px-4 py-2.5">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming"><x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button></template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> contact(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>
            <x-table flush>
                <thead><tr><th class="w-10"><x-select-all /></th><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Type</th><th>Target</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($alertContacts as $c)
                        <tr>
                            <td><x-select-one :id="$c->id" /></td>
                            <td class="font-medium text-slate-900">{{ $c->name }}</td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $c->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="text-slate-600">{{ $c->typeLabel() }}</td>
                            <td class="text-slate-500 font-mono text-xs">{{ $c->target }}</td>
                            <td>@if($c->is_enabled)<x-badge color="success" dot>Enabled</x-badge>@else<x-badge color="neutral">Disabled</x-badge>@endif</td>
                            <td class="text-right">
                                <x-icon-button :href="route('alerts.edit', $c)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-alert-' . $c->id" :action="route('alerts.destroy', $c)"
                                    title="Delete Alert Contact?" :message="'Remove ' . $c->name . '?'" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $alertContacts->links() }}</div>
    @endif
</x-layouts.app>
