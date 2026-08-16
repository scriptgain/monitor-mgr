<x-layouts.app :title="'Edit ' . $window->name">
    <x-page-header :title="'Edit ' . $window->name" icon="clock" :subtitle="$window->schedule()"
        :back="['href' => route('downtime.index'), 'label' => 'Downtime']" />
    <x-card>
        <form method="POST" action="{{ route('downtime.update', $window) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('downtime._fields', ['window' => $window])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('downtime.index') }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
