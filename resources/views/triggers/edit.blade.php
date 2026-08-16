<x-layouts.app :title="'Edit ' . $trigger->name">
    <x-page-header :title="'Edit ' . $trigger->name" icon="bolt" :subtitle="'Fires when ' . $trigger->condition() . '.'"
        :back="['href' => route('triggers.index'), 'label' => 'Triggers']" />

    <x-card>
        <form method="POST" action="{{ route('triggers.update', $trigger) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('triggers._fields', ['trigger' => $trigger])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('triggers.index') }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
