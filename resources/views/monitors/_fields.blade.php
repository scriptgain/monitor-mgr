{{-- Shared monitor fields. Expects $monitor (nullable). --}}
@php $m = $monitor ?? null; $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500'; @endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $m?->name)" required autofocus placeholder="e.g. Main Website" />
    </x-field>
    <x-field label="Type" for="type" required :error="$errors->first('type')" hint="Supports HTTP(S), TCP port, ping, keyword match, SSL certificate, DNS, heartbeat, and server agent checks.">
        <select id="type" name="type" class="{{ $inp }}">
            @foreach (\App\Models\Monitor::TYPES as $tc => $tl)
                <option value="{{ $tc }}" @selected(old('type', $m?->type ?? 'http') === $tc)>{{ $tl }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field label="Target" for="target" required hint="URL for HTTP/keyword/SSL, host for TCP/ping/DNS, or agent identifier." :error="$errors->first('target')">
    <x-input id="target" name="target" :value="old('target', $m?->target)" required placeholder="https://example.com or host.example.com" />
</x-field>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <x-field label="Port" for="port" hint="Required for TCP checks." :error="$errors->first('port')">
        <x-input id="port" name="port" type="number" min="1" max="65535" :value="old('port', $m?->port)" placeholder="443" />
    </x-field>
    <x-field label="Check Interval (sec)" for="interval_seconds" required :error="$errors->first('interval_seconds')">
        <x-input id="interval_seconds" name="interval_seconds" type="number" min="10" max="86400" :value="old('interval_seconds', $m?->interval_seconds ?? 60)" required />
    </x-field>
    <x-field label="Timeout (sec)" for="timeout_seconds" required :error="$errors->first('timeout_seconds')">
        <x-input id="timeout_seconds" name="timeout_seconds" type="number" min="1" max="300" :value="old('timeout_seconds', $m?->timeout_seconds ?? 30)" required />
    </x-field>
</div>

<x-field label="Expected Value" for="expected" hint="Optional, e.g. expected HTTP status code (200) or the keyword to look for." :error="$errors->first('expected')">
    <x-input id="expected" name="expected" :value="old('expected', $m?->expected)" placeholder="200" />
</x-field>

<x-field label="Status" for="status" required :error="$errors->first('status')">
    <select id="status" name="status" class="{{ $inp }}">
        @foreach (\App\Models\Monitor::STATUSES as $sc => $sl)
            <option value="{{ $sc }}" @selected(old('status', $m?->status ?? 'paused') === $sc)>{{ $sl }}</option>
        @endforeach
    </select>
</x-field>

<x-field label="Notes" for="notes" :error="$errors->first('notes')">
    <textarea id="notes" name="notes" rows="2" class="{{ $inp }}">{{ old('notes', $m?->notes) }}</textarea>
</x-field>
@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this monitor and its history.">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $m?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this monitor. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$m?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
