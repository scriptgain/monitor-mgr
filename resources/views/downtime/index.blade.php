<x-layouts.app title="Downtime">
    <x-page-header title="Downtime" icon="clock" subtitle="Planned windows that hold alerts back. Checks keep running and incidents still open, so the history has no hole in it.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('downtime.create') }}">New Window</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($windows->isEmpty())
        <x-card>
            <x-empty-state icon="clock" title="No Downtime Windows"
                description="Add one and a planned reboot stops paging the on-call list.">
                <x-slot:action><x-button icon="plus" href="{{ route('downtime.create') }}">New Window</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <x-table flush>
                <thead><tr>
                    <th>Name</th>
                    <th>Applies To</th>
                    <th>Schedule</th>
                    @if (auth()->user()->isAdmin())<th>Owner</th>@endif
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr></thead>
                <tbody>
                    @foreach ($windows as $w)
                        <tr>
                            <td class="font-medium text-slate-900">
                                {{ $w->name }}
                                @if ($w->coversTime())<x-badge color="warn">Active Now</x-badge>@endif
                            </td>
                            <td class="text-slate-600">{{ $w->subjectName() }}</td>
                            <td class="text-slate-600">{{ $w->schedule() }}</td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $w->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td>@if($w->is_enabled)<x-badge color="success" dot>Enabled</x-badge>@else<x-badge color="neutral">Disabled</x-badge>@endif</td>
                            <td class="text-right">
                                <x-icon-button :href="route('downtime.edit', $w)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-dt-' . $w->id" :action="route('downtime.destroy', $w)"
                                    title="Delete Downtime Window?" :message="'Remove ' . $w->name . '? Alerts it was holding back will resume.'" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $windows->links() }}</div>
    @endif
</x-layouts.app>
