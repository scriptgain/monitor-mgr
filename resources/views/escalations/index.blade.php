<x-layouts.app title="Escalations">
    <x-page-header title="Escalations" icon="bell" subtitle="Who gets told when an incident goes unacknowledged. Acknowledging it stops the ladder.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('escalations.create') }}">New Escalation</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($steps->isEmpty())
        <x-card>
            <x-empty-state icon="bell" title="No Escalations Yet"
                description="Without one, an incident alerts your contacts once and then waits. Add a step such as page the on-call phone after 15 minutes.">
                <x-slot:action><x-button icon="plus" href="{{ route('escalations.create') }}">New Escalation</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <x-table flush>
                <thead><tr>
                    <th>Step</th>
                    <th>Notifies</th>
                    <th>When</th>
                    <th>Applies To</th>
                    @if (auth()->user()->isAdmin())<th>Owner</th>@endif
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr></thead>
                <tbody>
                    @foreach ($steps as $s)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $s->name }}</td>
                            <td class="text-slate-600">{{ $s->contact?->name ?? 'Missing contact' }}</td>
                            <td class="text-slate-600">{{ $s->timing() }}</td>
                            <td class="text-slate-500">{{ $s->severityLabel() }}</td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $s->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td>@if($s->is_enabled)<x-badge color="success" dot>Enabled</x-badge>@else<x-badge color="neutral">Disabled</x-badge>@endif</td>
                            <td class="text-right">
                                <x-icon-button :href="route('escalations.edit', $s)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-esc-' . $s->id" :action="route('escalations.destroy', $s)"
                                    title="Delete Escalation?" :message="'Remove ' . $s->name . '?'" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $steps->links() }}</div>
    @endif
</x-layouts.app>
