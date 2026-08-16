<x-layouts.app :title="'Edit ' . $step->name">
    <x-page-header :title="'Edit ' . $step->name" icon="bell" :subtitle="$step->timing() . ' · ' . $step->severityLabel()"
        :back="['href' => route('escalations.index'), 'label' => 'Escalations']" />
    <x-card>
        <form method="POST" action="{{ route('escalations.update', $step) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('escalations._fields', ['step' => $step])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('escalations.index') }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
