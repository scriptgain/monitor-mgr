<x-layouts.app :title="'Edit ' . $contact->name">
    <x-page-header :title="'Edit ' . $contact->name" icon="bell" subtitle="Update this alert contact."
        :back="['href' => route('alerts.index'), 'label' => 'Alerts']" />

    <x-card>
        <form method="POST" action="{{ route('alerts.update', $contact) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('alerts._fields', ['contact' => $contact])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('alerts.index') }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
