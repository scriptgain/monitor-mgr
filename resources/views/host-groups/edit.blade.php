<x-layouts.app :title="'Edit ' . $group->name">
    <x-page-header :title="'Edit ' . $group->name" icon="server" :subtitle="$group->description ?: 'Host group'"
        :back="['href' => route('host-groups.index'), 'label' => 'Host Groups']" />
    <x-card>
        <form method="POST" action="{{ route('host-groups.update', $group) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('host-groups._fields', ['group' => $group])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('host-groups.index') }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
