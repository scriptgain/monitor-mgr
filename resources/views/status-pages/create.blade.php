<x-layouts.app title="New Status Page">
    <x-page-header title="New Status Page" icon="globe" subtitle="Pick which monitors to summarize."
        :back="['href' => route('status-pages.index'), 'label' => 'Status Pages']" />

    <x-card>
        <form method="POST" action="{{ route('status-pages.store') }}" class="space-y-5">
            @csrf
            @include('status-pages._fields', ['statusPage' => null, 'selected' => []])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('status-pages.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Status Page</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
