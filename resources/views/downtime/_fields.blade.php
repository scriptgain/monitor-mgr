{{-- Shared downtime window fields. Expects $window (nullable), $monitors, $hosts, optionally $owners. --}}
@php
    $w = $window ?? null;
    $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500';
    $subject = old('subject', $w?->monitor_id ? 'monitor:' . $w->monitor_id : ($w?->monitored_host_id ? 'host:' . $w->monitored_host_id : ''));
    $kind = old('kind', $w?->kind ?? 'once');
    $days = array_map('intval', (array) old('days_of_week', $w?->days_of_week ?? []));
@endphp

<div x-data="{ kind: '{{ $kind }}' }" class="space-y-5">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Name" for="name" required :error="$errors->first('name')">
            <x-input id="name" name="name" :value="old('name', $w?->name)" required autofocus placeholder="e.g. Sunday patching" />
        </x-field>
        <x-field label="Applies To" for="subject" hint="Leave on everything to silence the whole panel for the window.">
            <select id="subject" name="subject" class="{{ $inp }}">
                <option value="">Everything</option>
                @if ($monitors->isNotEmpty())
                    <optgroup label="Monitors">
                        @foreach ($monitors as $m)
                            <option value="monitor:{{ $m->id }}" @selected($subject === 'monitor:' . $m->id)>{{ $m->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
                @if ($hosts->isNotEmpty())
                    <optgroup label="Hosts">
                        @foreach ($hosts as $h)
                            <option value="host:{{ $h->id }}" @selected($subject === 'host:' . $h->id)>{{ $h->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
        </x-field>
    </div>

    <x-field label="Repeats" for="kind" required :error="$errors->first('kind')">
        <select id="kind" name="kind" x-model="kind" class="{{ $inp }}">
            @foreach (\App\Models\DowntimeWindow::KINDS as $kv => $kl)
                <option value="{{ $kv }}" @selected($kind === $kv)>{{ $kl }}</option>
            @endforeach
        </select>
    </x-field>

    <div x-show="kind === 'once'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Starts" for="starts_at" :error="$errors->first('starts_at')">
            <x-input id="starts_at" name="starts_at" type="datetime-local"
                :value="old('starts_at', $w?->starts_at?->format('Y-m-d\TH:i'))" />
        </x-field>
        <x-field label="Ends" for="ends_at" :error="$errors->first('ends_at')">
            <x-input id="ends_at" name="ends_at" type="datetime-local"
                :value="old('ends_at', $w?->ends_at?->format('Y-m-d\TH:i'))" />
        </x-field>
    </div>

    <div x-show="kind === 'weekly'" x-cloak class="space-y-5">
        <x-field label="Days" :error="$errors->first('days_of_week')">
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Models\DowntimeWindow::DAYS as $dv => $dl)
                    <label class="inline-flex items-center gap-2 rounded-lg ring-1 ring-inset ring-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
                        <input type="checkbox" name="days_of_week[]" value="{{ $dv }}" @checked(in_array($dv, $days, true))
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        {{ $dl }}
                    </label>
                @endforeach
            </div>
        </x-field>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <x-field label="From" for="start_time" hint="A window that ends before it starts runs over midnight." :error="$errors->first('start_time')">
                <x-input id="start_time" name="start_time" type="time" :value="old('start_time', $w ? substr((string) $w->start_time, 0, 5) : '02:00')" />
            </x-field>
            <x-field label="To" for="end_time" :error="$errors->first('end_time')">
                <x-input id="end_time" name="end_time" type="time" :value="old('end_time', $w ? substr((string) $w->end_time, 0, 5) : '04:00')" />
            </x-field>
        </div>
    </div>

    <x-field label="Notes" for="notes" :error="$errors->first('notes')">
        <x-input id="notes" name="notes" :value="old('notes', $w?->notes)" placeholder="What is happening, and who is doing it." />
    </x-field>

    <x-field label="Status">
        <x-toggle name="is_enabled" label="Enabled" :checked="old('is_enabled', $w?->is_enabled ?? true)" />
    </x-field>

    @isset($owners)
        @if ($owners->isNotEmpty())
            <x-field label="Owner" for="owner_id">
                <select id="owner_id" name="owner_id" class="{{ $inp }}">
                    <option value="">{{ auth()->user()->name }} (me)</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('owner_id', $w?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                    @endforeach
                </select>
            </x-field>
        @endif
    @endisset
</div>
