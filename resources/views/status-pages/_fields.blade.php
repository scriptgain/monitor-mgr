{{-- Shared status page fields. Expects $statusPage (nullable), $monitors, $selected (array of monitor ids). --}}
@php $sp = $statusPage ?? null; $sel = $selected ?? []; $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500'; @endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $sp?->name)" required autofocus placeholder="e.g. Public Status" />
    </x-field>
    <x-field label="Slug" for="slug" hint="Leave blank to auto-generate from the name." :error="$errors->first('slug')">
        <x-input id="slug" name="slug" :value="old('slug', $sp?->slug)" placeholder="public-status" />
    </x-field>
</div>

<x-field label="Description" for="description" :error="$errors->first('description')">
    <textarea id="description" name="description" rows="2" class="{{ $inp }}">{{ old('description', $sp?->description) }}</textarea>
</x-field>

<x-field label="Visibility">
    <x-toggle name="is_public" label="Publicly Visible" :checked="old('is_public', $sp?->is_public ?? false)" />
</x-field>

<x-field label="Monitors" hint="Pick which monitors appear on this status page.">
    <div class="rounded-lg ring-1 ring-inset ring-slate-300 divide-y divide-slate-100 max-h-72 overflow-y-auto">
        @forelse ($monitors as $m)
            <x-check-switch name="monitor_ids[]" :value="$m->id" :checked="in_array($m->id, old('monitor_ids', $sel))" class="w-full px-3 py-2 hover:bg-slate-50">
                <span class="text-slate-700">{{ $m->name }}</span>
                <span class="ml-2 text-slate-400 text-xs">{{ $m->typeLabel() }}</span>
            </x-check-switch>
        @empty
            <p class="px-3 py-3 text-sm text-slate-500">No monitors yet. Create one first.</p>
        @endforelse
    </div>
</x-field>
@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this status page.">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $sp?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this status page. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$sp?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
