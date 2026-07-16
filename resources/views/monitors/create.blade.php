<x-layouts.app title="New Monitor">
    <x-page-header title="New Monitor" icon="pulse" subtitle="Add a website, server, or service to watch."
        :back="['href' => route('monitors.index'), 'label' => 'Monitors']" />

    <x-card>
        <form method="POST" action="{{ route('monitors.store') }}" class="space-y-5">
            @csrf
            @include('monitors._fields', ['monitor' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('monitors.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Monitor</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
