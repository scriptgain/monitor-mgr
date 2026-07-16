<x-layouts.app :title="'Edit ' . $statusPage->name">
    <x-page-header :title="'Edit ' . $statusPage->name" icon="globe" subtitle="Update this status page."
        :back="['href' => route('status-pages.show', $statusPage), 'label' => $statusPage->name]" />

    <x-card>
        <form method="POST" action="{{ route('status-pages.update', $statusPage) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('status-pages._fields', ['statusPage' => $statusPage, 'selected' => $selected])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('status-pages.show', $statusPage) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
