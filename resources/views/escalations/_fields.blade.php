{{-- Shared escalation step fields. Expects $step (nullable), $contacts, $severities, optionally $owners. --}}
@php
    $s = $step ?? null;
    $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500';
@endphp

@if ($contacts->isEmpty())
    <x-alert type="warn">
        There are no alert contacts yet, and an escalation needs somewhere to send to.
        <a href="{{ route('alerts.create') }}" class="font-medium underline">Add one first</a>.
    </x-alert>
@endif

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $s?->name)" required autofocus placeholder="e.g. Page the on-call phone" />
    </x-field>
    <x-field label="Notify" for="alert_contact_id" required hint="One contact per step. Add more steps to reach more people." :error="$errors->first('alert_contact_id')">
        <select id="alert_contact_id" name="alert_contact_id" class="{{ $inp }}">
            @foreach ($contacts as $contact)
                <option value="{{ $contact->id }}" @selected(old('alert_contact_id', $s?->alert_contact_id) == $contact->id)>{{ $contact->name }} ({{ $contact->typeLabel() }})</option>
            @endforeach
        </select>
    </x-field>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <x-field label="After (minutes)" for="after_minutes" required hint="Measured from when the incident opened. Zero sends alongside the first alert." :error="$errors->first('after_minutes')">
        <x-input id="after_minutes" name="after_minutes" type="number" min="0" max="10080" :value="old('after_minutes', $s?->after_minutes ?? 15)" required />
    </x-field>
    <x-field label="Repeat Every (minutes)" for="repeat_minutes" hint="Leave blank to send once." :error="$errors->first('repeat_minutes')">
        <x-input id="repeat_minutes" name="repeat_minutes" type="number" min="1" max="1440" :value="old('repeat_minutes', $s?->repeat_minutes)" placeholder="e.g. 30" />
    </x-field>
    <x-field label="Only At Or Above" for="min_severity" hint="Leave on any severity to escalate everything." :error="$errors->first('min_severity')">
        <select id="min_severity" name="min_severity" class="{{ $inp }}">
            <option value="">Any severity</option>
            @foreach ($severities as $sv => $sl)
                <option value="{{ $sv }}" @selected(old('min_severity', $s?->min_severity) === $sv)>{{ $sl }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field label="Status">
    <x-toggle name="is_enabled" label="Enabled" :checked="old('is_enabled', $s?->is_enabled ?? true)" />
</x-field>

@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $s?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
    @endif
@endisset
