<x-layouts.app title="New Escalation">
    <x-page-header title="New Escalation" icon="bell" subtitle="Who gets told when an incident goes unacknowledged."
        :back="['href' => route('escalations.index'), 'label' => 'Escalations']" />
    <x-card>
        <form method="POST" action="{{ route('escalations.store') }}" class="space-y-5">
            @csrf
            @include('escalations._fields', ['step' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('escalations.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Escalation</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
