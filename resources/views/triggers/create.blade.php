<x-layouts.app title="New Trigger">
    <x-page-header title="New Trigger" icon="bolt" subtitle="A threshold that turns host metrics into an incident."
        :back="['href' => route('triggers.index'), 'label' => 'Triggers']" />

    <x-card>
        <form method="POST" action="{{ route('triggers.store') }}" class="space-y-5">
            @csrf
            @include('triggers._fields', ['trigger' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('triggers.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Trigger</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
