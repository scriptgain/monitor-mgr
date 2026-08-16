<x-layouts.app title="Host Groups">
    <x-page-header title="Host Groups" icon="server" subtitle="Name a set of hosts once, then point triggers and downtime windows at it instead of copying rules.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('host-groups.create') }}">New Group</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($groups->isEmpty())
        <x-card>
            <x-empty-state icon="server" title="No Host Groups Yet"
                description="Without one, a rule is either fleet-wide or for a single host. A group is the in between.">
                <x-slot:action><x-button icon="plus" href="{{ route('host-groups.create') }}">New Group</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <x-table flush>
                <thead><tr>
                    <th>Group</th><th>Hosts</th><th>Triggers</th><th>Downtime</th>
                    @if (auth()->user()->isAdmin())<th>Owner</th>@endif
                    <th class="text-right">Actions</th>
                </tr></thead>
                <tbody>
                    @foreach ($groups as $g)
                        <tr>
                            <td>
                                <x-badge :color="$g->badgeColor()">{{ $g->name }}</x-badge>
                                @if ($g->description)<div class="text-xs text-slate-400 mt-1">{{ $g->description }}</div>@endif
                            </td>
                            <td class="tabular text-slate-600">{{ $g->hosts_count }}</td>
                            <td class="tabular text-slate-600">{{ $g->triggers_count }}</td>
                            <td class="tabular text-slate-600">{{ $g->downtime_windows_count }}</td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $g->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="text-right">
                                <x-icon-button :href="route('host-groups.edit', $g)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-hg-' . $g->id" :action="route('host-groups.destroy', $g)"
                                    title="Delete Host Group?"
                                    :message="'Remove ' . $g->name . '? The hosts stay, but ' . $g->triggers_count . ' trigger(s) and ' . $g->downtime_windows_count . ' downtime window(s) aimed at this group are deleted with it.'" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <div class="mt-4">{{ $groups->links() }}</div>
    @endif
</x-layouts.app>
