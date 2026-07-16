<x-layouts.app :title="$monitor->name">
    <x-page-header :title="$monitor->name" icon="pulse"
        :subtitle="$monitor->typeLabel() . ' · ' . $monitor->target . ($monitor->port ? ':' . $monitor->port : '')"
        :back="['href' => route('monitors.index'), 'label' => 'Monitors']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('monitors.edit', $monitor) }}">Edit</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Lifecycle --}}
            <x-card>
                <div class="flex flex-wrap items-center gap-3">
                    <x-badge :color="['up' => 'success', 'down' => 'danger', 'paused' => 'neutral'][$monitor->status] ?? 'neutral'" dot>{{ $monitor->statusLabel() }}</x-badge>
                    <span class="text-sm text-slate-500">Uptime <span class="font-medium tabular text-slate-700">{{ number_format($monitor->uptime_ratio, 2) }}%</span></span>
                    <span class="text-sm text-slate-500">Last checked <span class="font-medium text-slate-700">{{ optional($monitor->last_checked_at)->diffForHumans() ?? 'Never' }}</span></span>
                    <span class="flex-1"></span>
                    <x-delete-button :name="'del-mon'" :action="route('monitors.destroy', $monitor)"
                        title="Delete Monitor?" message="This removes the monitor and all of its recorded checks, incidents, and metrics." />
                </div>
            </x-card>

            {{-- Details --}}
            <x-card title="Details">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-500">Type</dt><dd class="text-slate-900">{{ $monitor->typeLabel() }}</dd></div>
                    <div><dt class="text-slate-500">Target</dt><dd class="font-mono text-slate-900 break-all">{{ $monitor->target }}{{ $monitor->port ? ':' . $monitor->port : '' }}</dd></div>
                    <div><dt class="text-slate-500">Check Interval</dt><dd class="text-slate-900">Every {{ $monitor->interval_seconds }}s</dd></div>
                    <div><dt class="text-slate-500">Timeout</dt><dd class="text-slate-900">{{ $monitor->timeout_seconds }}s</dd></div>
                    <div><dt class="text-slate-500">Expected</dt><dd class="text-slate-900">{{ $monitor->expected ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Open Incident</dt><dd class="text-slate-900">
                        @if ($openIncident)
                            <a href="{{ route('incidents.show', $openIncident) }}" class="text-brand-700 hover:underline">Since {{ $openIncident->started_at->diffForHumans() }}</a>
                        @else
                            None
                        @endif
                    </dd></div>
                </dl>
                @if ($monitor->notes)
                    <div class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-600">{{ $monitor->notes }}</div>
                @endif
            </x-card>

            {{-- Recent checks --}}
            <x-card title="Recent Checks">
                @if ($checks->isEmpty())
                    <p class="text-sm text-slate-500">No checks recorded yet.</p>
                @else
                    <x-table>
                        <thead><tr><th>Checked At</th><th>Status</th><th>Response</th><th>Code</th><th>Message</th></tr></thead>
                        <tbody>
                            @foreach ($checks as $c)
                                <tr>
                                    <td class="text-slate-500">{{ $c->checked_at->format('M j, Y g:i:s A') }}</td>
                                    <td><x-badge :color="$c->status === 'up' ? 'success' : 'danger'" dot>{{ ucfirst($c->status) }}</x-badge></td>
                                    <td class="tabular text-slate-500">{{ $c->response_time_ms !== null ? $c->response_time_ms . ' ms' : '—' }}</td>
                                    <td class="tabular text-slate-500">{{ $c->status_code ?? '—' }}</td>
                                    <td class="text-slate-500">{{ $c->message ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>

            {{-- Incidents --}}
            <x-card title="Incidents ({{ $incidents->count() }})">
                @if ($incidents->isEmpty())
                    <p class="text-sm text-slate-500">No incidents recorded.</p>
                @else
                    <x-table>
                        <thead><tr><th>Started</th><th>Resolved</th><th>Duration</th><th>Cause</th></tr></thead>
                        <tbody>
                            @foreach ($incidents as $i)
                                <tr>
                                    <td class="text-slate-500"><a href="{{ route('incidents.show', $i) }}" class="text-brand-700 hover:underline">{{ $i->started_at->format('M j, Y g:i A') }}</a></td>
                                    <td class="text-slate-500">{{ $i->resolved_at ? $i->resolved_at->format('M j, Y g:i A') : '—' }}</td>
                                    <td class="tabular text-slate-500">{{ $i->duration_seconds ? gmdate('H:i:s', $i->duration_seconds) : ($i->isOpen() ? 'Ongoing' : '—') }}</td>
                                    <td class="text-slate-500">{{ $i->cause ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            @if ($monitor->isAgentType())
                <x-card title="Latest Server Metrics">
                    @if (! $latestMetric)
                        <p class="text-sm text-slate-500">No metrics reported yet.</p>
                    @else
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">CPU</dt><dd class="font-medium tabular text-slate-900">{{ $latestMetric->cpu_pct !== null ? number_format($latestMetric->cpu_pct, 1) . '%' : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Memory</dt><dd class="font-medium tabular text-slate-900">{{ $latestMetric->mem_pct !== null ? number_format($latestMetric->mem_pct, 1) . '%' : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Disk</dt><dd class="font-medium tabular text-slate-900">{{ $latestMetric->disk_pct !== null ? number_format($latestMetric->disk_pct, 1) . '%' : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Load (1m)</dt><dd class="font-medium tabular text-slate-900">{{ $latestMetric->load1 ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Recorded</dt><dd class="text-slate-700">{{ $latestMetric->recorded_at->diffForHumans() }}</dd></div>
                        </dl>
                    @endif
                </x-card>
            @endif

            {{-- Demo/manual check recorder: real checks would arrive via an
                 external checker process or agent; this simulates one for now. --}}
            <x-card title="Record A Check" subtitle="Simulates a check result (demo, no live poller yet).">
                <form method="POST" action="{{ route('monitors.checks.store', $monitor) }}" class="space-y-4">
                    @csrf
                    <x-field label="Result" for="check_status" required>
                        <select id="check_status" name="status" class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">
                            <option value="up">Up</option>
                            <option value="down">Down</option>
                        </select>
                    </x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Response (ms)" for="response_time_ms">
                            <x-input id="response_time_ms" name="response_time_ms" type="number" min="0" placeholder="120" />
                        </x-field>
                        <x-field label="Status Code" for="status_code">
                            <x-input id="status_code" name="status_code" type="number" min="0" placeholder="200" />
                        </x-field>
                    </div>
                    <x-field label="Message" for="message">
                        <x-input id="message" name="message" placeholder="Optional detail" />
                    </x-field>
                    <x-button type="submit" size="sm" icon="pulse">Record Check</x-button>
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>
