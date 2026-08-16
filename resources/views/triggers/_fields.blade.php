{{-- Shared trigger fields. Expects $trigger (nullable), $hosts, optionally $owners. --}}
@php
    $t = $trigger ?? null;
    $inp = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500';
    $metric = old('metric', $t?->metric ?? 'cpu_pct');
    $target = old('target', $t?->monitored_host_id ? 'host:' . $t->monitored_host_id : ($t?->host_group_id ? 'group:' . $t->host_group_id : ''));
    $isCustomMetric = ! array_key_exists($metric, \App\Models\Trigger::METRICS);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Name" for="name" required :error="$errors->first('name')">
        <x-input id="name" name="name" :value="old('name', $t?->name)" required autofocus placeholder="e.g. Disk above 90%" />
    </x-field>
    <x-field label="Applies To" for="target" hint="The most specific rule for a metric wins: one host beats a group, a group beats the whole fleet." :error="$errors->first('target')">
        <select id="target" name="target" class="{{ $inp }}">
            <option value="">Every host</option>
            @if ($groups->isNotEmpty())
                <optgroup label="Groups">
                    @foreach ($groups as $group)
                        <option value="group:{{ $group->id }}" @selected($target === 'group:' . $group->id)>{{ $group->name }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($hosts->isNotEmpty())
                <optgroup label="Hosts">
                    @foreach ($hosts as $host)
                        <option value="host:{{ $host->id }}" @selected($target === 'host:' . $host->id)>{{ $host->name }}</option>
                    @endforeach
                </optgroup>
            @endif
        </select>
    </x-field>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <x-field label="Metric" for="metric_select" required :error="$errors->first('metric')">
        <select id="metric_select" name="metric" class="{{ $inp }}">
            @foreach (\App\Models\Trigger::METRICS as $mv => $ml)
                <option value="{{ $mv }}" @selected($metric === $mv)>{{ $ml }}</option>
            @endforeach
            @if ($isCustomMetric)
                <option value="{{ $metric }}" selected>{{ $metric }}</option>
            @endif
        </select>
    </x-field>
    <x-field label="Condition" for="operator" required :error="$errors->first('operator')">
        <select id="operator" name="operator" class="{{ $inp }}">
            @foreach (\App\Models\Trigger::OPERATORS as $ov => $ol)
                <option value="{{ $ov }}" @selected(old('operator', $t?->operator ?? '>') === $ov)>{{ ucfirst($ol) }}</option>
            @endforeach
        </select>
    </x-field>
    <x-field label="Threshold" for="threshold" required hint="Percent, load average, or bytes per second, depending on the metric." :error="$errors->first('threshold')">
        <x-input id="threshold" name="threshold" type="number" step="0.01" :value="old('threshold', $t?->threshold ?? 90)" required />
    </x-field>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <x-field label="Recovery Value" for="recovery_threshold" hint="The value it has to come back past before the incident closes. Leave blank and it will flap at the threshold." :error="$errors->first('recovery_threshold')">
        <x-input id="recovery_threshold" name="recovery_threshold" type="number" step="0.01" :value="old('recovery_threshold', $t?->recovery_threshold)" placeholder="e.g. 80" />
    </x-field>
    <x-field label="Sustained For (seconds)" for="for_seconds" required hint="The condition must hold this long before it fires. Zero fires on the first sample." :error="$errors->first('for_seconds')">
        <x-input id="for_seconds" name="for_seconds" type="number" min="0" max="86400" :value="old('for_seconds', $t?->for_seconds ?? 300)" required />
    </x-field>
    <x-field label="Severity" for="severity" required :error="$errors->first('severity')">
        <select id="severity" name="severity" class="{{ $inp }}">
            @foreach (\App\Models\Trigger::SEVERITIES as $sv => $sl)
                <option value="{{ $sv }}" @selected(old('severity', $t?->severity ?? 'average') === $sv)>{{ $sl }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field label="Notes" for="notes" :error="$errors->first('notes')">
    <x-input id="notes" name="notes" :value="old('notes', $t?->notes)" placeholder="Why this rule exists, and who to call." />
</x-field>

<x-field label="Status">
    <x-toggle name="is_enabled" label="Enabled" :checked="old('is_enabled', $t?->is_enabled ?? true)" />
</x-field>

@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this trigger.">
            <select id="owner_id" name="owner_id" class="{{ $inp }}">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $t?->user_id) == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this trigger. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$t?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
