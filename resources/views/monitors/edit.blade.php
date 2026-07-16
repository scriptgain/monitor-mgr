<x-layouts.app :title="'Edit ' . $monitor->name">
    <x-page-header :title="'Edit ' . $monitor->name" icon="pulse" subtitle="Update this monitor's settings."
        :back="['href' => route('monitors.show', $monitor), 'label' => $monitor->name]" />

    <x-card>
        <form method="POST" action="{{ route('monitors.update', $monitor) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('monitors._fields', ['monitor' => $monitor])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('monitors.show', $monitor) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
