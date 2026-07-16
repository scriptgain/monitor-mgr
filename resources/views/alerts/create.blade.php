<x-layouts.app title="New Alert Contact">
    <x-page-header title="New Alert Contact" icon="bell" subtitle="Add a place to send monitor alerts."
        :back="['href' => route('alerts.index'), 'label' => 'Alerts']" />

    <x-card>
        <form method="POST" action="{{ route('alerts.store') }}" class="space-y-5">
            @csrf
            @include('alerts._fields', ['contact' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('alerts.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Alert Contact</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
