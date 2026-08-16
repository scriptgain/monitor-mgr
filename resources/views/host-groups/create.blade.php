<x-layouts.app title="New Host Group">
    <x-page-header title="New Host Group" icon="server" subtitle="A named set of hosts that triggers and downtime windows can aim at."
        :back="['href' => route('host-groups.index'), 'label' => 'Host Groups']" />
    <x-card>
        <form method="POST" action="{{ route('host-groups.store') }}" class="space-y-5">
            @csrf
            @include('host-groups._fields', ['group' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('host-groups.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Group</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
