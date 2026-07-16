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
        <x-table>
            <thead><tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Slug</th><th>Monitors</th><th>Visibility</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @foreach ($statusPages as $sp)
                    <tr>
                        <td><a href="{{ route('status-pages.show', $sp) }}" class="text-brand-700 hover:underline font-medium">{{ $sp->name }}</a></td>
                        @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $sp->owner?->name ?? 'Unassigned' }}</td>@endif
                        <td class="text-slate-500 font-mono text-xs">{{ $sp->slug }}</td>
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
        <div class="mt-4">{{ $statusPages->links() }}</div>
    @endif
</x-layouts.app>
