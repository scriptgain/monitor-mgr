{{-- Shared host group fields. Expects $group (nullable), $hosts, optionally $owners. --}}
@php
    $g = $group ?? null;
    $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500';
    $selected = array_map('intval', (array) old('hosts', $g?->hosts?->pluck('id')->all() ?? []));
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $g?->name)" required autofocus placeholder="e.g. Production Web" />
    </x-field>
    <x-field label="Color" for="color" hint="Shown as a chip on the hosts list." :error="$errors->first('color')">
        <select id="color" name="color" class="{{ $inp }}">
            @foreach (\App\Models\HostGroup::COLORS as $cv => $cl)
                <option value="{{ $cv }}" @selected(old('color', $g?->color ?? 'neutral') === $cv)>{{ $cl }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field label="Description" for="description" :error="$errors->first('description')">
    <x-input id="description" name="description" :value="old('description', $g?->description)" placeholder="What belongs in here, and why." />
</x-field>

<x-field label="Members" hint="A host can be in as many groups as you like.">
    @if ($hosts->isEmpty())
        <p class="text-sm text-slate-500">No hosts have been added yet, so there is nothing to put in a group.</p>
    @else
        <div class="max-h-72 overflow-y-auto rounded-lg ring-1 ring-inset ring-slate-200 divide-y divide-slate-100">
            @foreach ($hosts as $host)
                <label class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" name="hosts[]" value="{{ $host->id }}" @checked(in_array($host->id, $selected, true))
                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span class="font-medium text-slate-900">{{ $host->name }}</span>
                    @if ($host->hostname)<span class="text-slate-400 font-mono text-xs">{{ $host->hostname }}</span>@endif
                </label>
            @endforeach
        </div>
    @endif
</x-field>

@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $g?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
    @endif
@endisset
