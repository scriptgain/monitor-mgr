<x-layouts.app title="Add Host">
    <x-page-header title="Add Host" icon="server" subtitle="Register A Host, Then Install The Agent With A Single Command."
        :back="['href' => route('hosts.index'), 'label' => 'Hosts']" />

    <div class="max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('hosts.store') }}" class="space-y-5">
                @csrf
                <x-field label="Host Name" for="name" required
                    hint="A friendly label for this machine, e.g. web-01 or db-primary."
                    :error="$errors->first('name')">
                    <x-input id="name" name="name" value="{{ old('name') }}" placeholder="web-01" required autofocus />
                </x-field>

                <x-field label="Notes" for="notes" :error="$errors->first('notes')">
                    <textarea id="notes" name="notes" rows="3"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
                        placeholder="Optional">{{ old('notes') }}</textarea>
                </x-field>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-button variant="secondary" href="{{ route('hosts.index') }}">Cancel</x-button>
                    <x-button type="submit" icon="plus">Create And Show Install Command</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
