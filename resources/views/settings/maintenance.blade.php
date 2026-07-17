<x-layouts.app title="Maintenance">
    <x-page-header title="Maintenance" icon="refresh" subtitle="Telemetry retention, incident history, and audit pruning windows."
        :back="['href' => route('settings.index'), 'label' => 'Settings']" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ route('settings.maintenance.update') }}" class="space-y-6">
                @csrf @method('PUT')

                <x-card title="Automatic Maintenance" subtitle="A scheduled sweep that keeps telemetry, incidents, and logs tidy.">
                    <x-toggle name="auto_maintenance" :checked="$v['auto_maintenance'] === '1'"
                        label="Run Maintenance Automatically"
                        description="When on, the hourly sweep runs inside the window below and applies the tasks you enable." />
                </x-card>

                <x-card title="Maintenance Window" subtitle="Confine the automatic sweep to off-peak hours.">
                    <x-toggle name="maintenance_window_enabled" :checked="$v['maintenance_window_enabled'] === '1'"
                        label="Restrict To A Window"
                        description="When off, the sweep may run any hour. Manual runs always ignore the window." />

                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-5 border-t border-slate-100 pt-5">
                        <x-field label="Window Start" for="maintenance_window_start" :error="$errors->first('maintenance_window_start')"
                            hint="Local time in {{ config('app.timezone') }}.">
                            <x-input type="time" id="maintenance_window_start" name="maintenance_window_start" value="{{ $v['maintenance_window_start'] }}" />
                        </x-field>
                        <x-field label="Window End" for="maintenance_window_end" :error="$errors->first('maintenance_window_end')"
                            hint="Ends before it starts? The window wraps past midnight.">
                            <x-input type="time" id="maintenance_window_end" name="maintenance_window_end" value="{{ $v['maintenance_window_end'] }}" />
                        </x-field>
                    </div>

                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <span class="block text-sm font-medium text-slate-700 mb-2">Days The Sweep May Run</span>
                        <div class="flex flex-wrap gap-x-6 gap-y-3">
                            @foreach ($days as $day)
                                <x-check-switch name="maintenance_days[]" :value="$day" :checked="in_array($day, $selectedDays, true)" class="capitalize">{{ $day }}</x-check-switch>
                            @endforeach
                        </div>
                        @error('maintenance_days.*')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </x-card>

                <x-card title="Housekeeping Tasks" subtitle="What the sweep does each time it runs.">
                    <div class="space-y-5">
                        <div>
                            <x-toggle name="prune_telemetry" :checked="$v['prune_telemetry'] === '1'"
                                label="Prune Old Telemetry"
                                description="Delete check results and server metrics older than the retention window below." />
                            <div class="mt-4 sm:max-w-xs">
                                <x-field label="Keep Telemetry (Days)" for="telemetry_days" :error="$errors->first('telemetry_days')"
                                    hint="Check + metric rows older than this are pruned.">
                                    <x-input type="number" id="telemetry_days" name="telemetry_days" min="1" max="3650" value="{{ $v['telemetry_days'] }}" />
                                </x-field>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-5">
                            <x-toggle name="prune_resolved_incidents" :checked="$v['prune_resolved_incidents'] === '1'"
                                label="Prune Resolved Incidents"
                                description="Delete incidents that were resolved long ago. Open incidents are never touched." />
                            <div class="mt-4 sm:max-w-xs">
                                <x-field label="Keep Resolved Incidents (Days)" for="incident_days" :error="$errors->first('incident_days')"
                                    hint="Resolved incidents older than this are pruned.">
                                    <x-input type="number" id="incident_days" name="incident_days" min="1" max="3650" value="{{ $v['incident_days'] }}" />
                                </x-field>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-5 sm:max-w-xs">
                            <x-field label="Keep Audit Log (Days)" for="audit_log_days" :error="$errors->first('audit_log_days')"
                                hint="Audit rows older than this are pruned. 0 = keep forever.">
                                <x-input type="number" id="audit_log_days" name="audit_log_days" min="0" max="3650" value="{{ $v['audit_log_days'] }}" />
                            </x-field>
                        </div>
                    </div>
                </x-card>

                <div class="flex justify-end gap-3 sticky bottom-4">
                    <div class="flex gap-3 rounded-xl bg-white/90 backdrop-blur ring-1 ring-slate-200 shadow-sm px-4 py-3">
                        <x-button variant="secondary" type="button" onclick="window.location.reload()">Reset</x-button>
                        <x-button variant="primary" type="submit" icon="check">Save Settings</x-button>
                    </div>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-4 py-2.5">
                        <dt class="text-slate-500 shrink-0">Sweep Right Now</dt>
                        <dd class="text-right">
                            @if ($allowedNow)
                                <x-badge color="success">Allowed</x-badge>
                            @else
                                <x-badge color="neutral">Outside Window</x-badge>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-2.5">
                        <dt class="text-slate-500 shrink-0">Server Time</dt>
                        <dd class="font-medium text-slate-900 text-right">{{ $now->format('g:i A T') }}</dd>
                    </div>
                    @foreach ($stats as $label => $value)
                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 shrink-0">{{ $label }}</dt>
                            <dd class="font-medium text-slate-900 text-right">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Run Now" subtitle="Apply the enabled tasks immediately, ignoring the window.">
                <form method="POST" action="{{ route('settings.maintenance.run') }}">
                    @csrf
                    <x-button variant="secondary" type="submit" icon="refresh" class="w-full justify-center">Run Maintenance Now</x-button>
                </form>
                <p class="mt-3 text-xs text-slate-400">Save your changes first — Run Now uses the currently saved settings.</p>
            </x-card>
        </div>
    </div>
</x-layouts.app>
