<x-layouts.app title="New Downtime Window">
    <x-page-header title="New Downtime Window" icon="clock" subtitle="Hold alerts during planned work. Checks keep running."
        :back="['href' => route('downtime.index'), 'label' => 'Downtime']" />
    <x-card>
        <form method="POST" action="{{ route('downtime.store') }}" class="space-y-5">
            @csrf
            @include('downtime._fields', ['window' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('downtime.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Window</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
