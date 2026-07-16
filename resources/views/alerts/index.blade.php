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
        <x-table>
            <thead><tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Type</th><th>Target</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @foreach ($alertContacts as $c)
                    <tr>
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
        <div class="mt-4">{{ $alertContacts->links() }}</div>
    @endif
</x-layouts.app>
