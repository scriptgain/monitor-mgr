<x-layouts.app title="Status Pages">
    <x-page-header title="Status Pages" icon="globe" subtitle="Public or private pages that summarize a set of monitors.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('status-pages.create') }}">New Status Page</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($statusPages->isEmpty())
        <x-card>
            <x-empty-state icon="globe" title="No Status Pages Yet" description="Group monitors into a page you can share with customers.">
                <x-slot:action><x-button icon="plus" href="{{ route('status-pages.create') }}">New Status Page</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        @php $base = rtrim(config('app.url'), '/'); @endphp
        <div x-data="{ selected: [], confirming: false, allIds: [{{ $statusPages->pluck('id')->implode(',') }}], submitBulk() { const f = this.$refs.bulkForm; f.querySelectorAll('input.js-dyn').forEach(n => n.remove()); this.selected.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; i.className='js-dyn'; f.appendChild(i); }); f.submit(); } }"
             class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <form method="POST" action="{{ route('status-pages.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-brand-50 px-4 py-2.5">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming"><x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button></template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> page(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>
            <x-table flush>
                <thead><tr><th class="w-10"><x-select-all /></th><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>URL</th><th>Monitors</th><th>Visibility</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($statusPages as $sp)
                        <tr>
                            <td><x-select-one :id="$sp->id" /></td>
                            <td><a href="{{ route('status-pages.show', $sp) }}" class="text-brand-700 hover:underline font-medium">{{ $sp->name }}</a></td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $sp->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="font-mono text-xs">
                                <a href="{{ $base }}/status/{{ $sp->slug }}" target="_blank" class="text-brand-700 hover:underline">{{ $base }}/status/{{ $sp->slug }}</a>
                            </td>
                            <td class="tabular text-slate-500">{{ $sp->monitors_count }}</td>
                            <td>@if($sp->is_public)<x-badge color="success">Public</x-badge>@else<x-badge color="neutral">Private</x-badge>@endif</td>
                            <td class="text-right">
                                <x-icon-button :href="route('status-pages.show', $sp)" icon="eye" title="Open" />
                                <x-icon-button :href="route('status-pages.edit', $sp)" icon="edit" title="Edit" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $statusPages->links() }}</div>
    @endif
</x-layouts.app>
