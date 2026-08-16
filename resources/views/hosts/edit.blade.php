<x-layouts.app :title="'Edit ' . $host->name">
    <x-page-header :title="'Edit ' . $host->name" icon="server" subtitle="Rename it, label it, and choose which groups it belongs to."
        :back="['href' => route('hosts.show', $host), 'label' => $host->name]" />

    @php $selected = array_map('intval', (array) old('groups', $host->groups->pluck('id')->all())); @endphp

    <div class="max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('hosts.update', $host) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-field label="Host Name" for="name" required :error="$errors->first('name')">
                    <x-input id="name" name="name" :value="old('name', $host->name)" required autofocus />
                </x-field>

                <x-field label="Tags" for="tags" hint="Comma separated. Lower cased and deduplicated when saved." :error="$errors->first('tags')">
                    <x-input id="tags" name="tags" :value="old('tags', implode(', ', $host->tagList()))" placeholder="production, web, eu-west" />
                </x-field>

                <x-field label="Groups" hint="A host can be in as many groups as you like.">
                    @if ($groups->isEmpty())
                        <p class="text-sm text-slate-500">No host groups exist yet.
                            <a href="{{ route('host-groups.create') }}" class="text-brand-700 hover:underline">Create one</a>.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($groups as $g)
                                <label class="inline-flex items-center gap-2 rounded-lg ring-1 ring-inset ring-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
                                    <input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $selected, true))
                                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ $g->name }}
                                </label>
                            @endforeach
                        </div>
                    @endif
                </x-field>

                <x-field label="Notes" for="notes" :error="$errors->first('notes')">
                    <textarea id="notes" name="notes" rows="3"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
                        placeholder="Optional">{{ old('notes', $host->notes) }}</textarea>
                </x-field>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-button variant="secondary" href="{{ route('hosts.show', $host) }}">Cancel</x-button>
                    <x-button type="submit" icon="check">Save Changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
