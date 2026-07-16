<x-layouts.app :title="$statusPage->name">
    <x-page-header :title="$statusPage->name" icon="globe"
        :subtitle="$statusPage->is_public ? 'Public status page' : 'Private status page'"
        :back="['href' => route('status-pages.index'), 'label' => 'Status Pages']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('status-pages.edit', $statusPage) }}">Edit</x-button>
            <x-delete-button :name="'del-sp'" :action="route('status-pages.destroy', $statusPage)"
                title="Delete Status Page?" message="This removes the page. Monitors themselves are not affected." />
        </x-slot:actions>
    </x-page-header>

    @if ($statusPage->description)
        <x-card class="mb-6"><p class="text-sm text-slate-600">{{ $statusPage->description }}</p></x-card>
    @endif

    <x-card title="Monitors On This Page">
        @if ($statusPage->monitors->isEmpty())
            <x-empty-state icon="pulse" title="No Monitors Assigned" description="Edit this page to add monitors." />
        @else
            <x-table>
                <thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Uptime</th></tr></thead>
                <tbody>
                    @foreach ($statusPage->monitors as $m)
                        <tr>
                            <td><a href="{{ route('monitors.show', $m) }}" class="text-brand-700 hover:underline font-medium">{{ $m->name }}</a></td>
                            <td class="text-slate-600">{{ $m->typeLabel() }}</td>
                            <td><x-badge :color="['up' => 'success', 'down' => 'danger', 'paused' => 'neutral'][$m->status] ?? 'neutral'" dot>{{ $m->statusLabel() }}</x-badge></td>
                            <td class="tabular text-slate-500">{{ number_format($m->uptime_ratio, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
