{{-- Shared alert contact fields. Expects $contact (nullable). --}}
@php $c = $contact ?? null; $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500'; @endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $c?->name)" required autofocus placeholder="e.g. On-Call Email" />
    </x-field>
    <x-field label="Type" for="type" required :error="$errors->first('type')">
        <select id="type" name="type" class="{{ $inp }}">
            @foreach (\App\Models\AlertContact::TYPES as $tc => $tl)
                <option value="{{ $tc }}" @selected(old('type', $c?->type ?? 'email') === $tc)>{{ $tl }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field label="Target" for="target" required hint="Email address, webhook URL, phone number, or Slack channel/webhook." :error="$errors->first('target')">
    <x-input id="target" name="target" :value="old('target', $c?->target)" required placeholder="ops@example.com" />
</x-field>

<x-field label="Status">
    <x-toggle name="is_enabled" label="Enabled" :checked="old('is_enabled', $c?->is_enabled ?? true)" />
</x-field>
@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this alert contact.">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $c?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this alert contact. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$c?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
