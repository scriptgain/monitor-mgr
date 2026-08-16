<x-layouts.app title="Reports">
    <x-page-header title="Availability" icon="book"
        subtitle="Time weighted, computed from incident durations, with planned downtime excluded.">
        <x-slot:actions>
            <x-button variant="secondary" icon="download" href="{{ route('reports.export', ['period' => $period]) }}">Export CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        @foreach ($periods as $key => $label)
            <a href="{{ route('reports.index', ['period' => $key]) }}"
               @class(['px-3 py-1.5 rounded-lg font-medium',
                       'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' => $period === $key,
                       'text-slate-600 hover:bg-slate-100' => $period !== $key])>Last {{ $label }}</a>
        @endforeach
        <span class="flex-1"></span>
        <span class="text-xs text-slate-400">{{ $from->format('M j, Y g:i A') }} to {{ $to->format('M j, Y g:i A') }}</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Overall Uptime" :value="$overall === null ? 'No data' : number_format($overall, 3) . '%'" icon="check-circle" />
        <x-stat label="Total Downtime" :value="$totalDowntime ? gmdate('H:i:s', $totalDowntime % 86400) . ($totalDowntime >= 86400 ? ' +' . intdiv($totalDowntime, 86400) . 'd' : '') : 'None'" icon="clock" />
        <x-stat label="Incidents" :value="$totalIncidents" icon="warning" />
    </div>

    @if ($rows->isEmpty())
        <x-card>
            <x-empty-state icon="book" title="Nothing To Report"
                description="Add a monitor or enroll a host and this fills in as incidents open and close." />
        </x-card>
    @else
        <div class="rounded-xl ring-1 ring-slate-200 bg-white shadow-sm overflow-hidden">
            <x-table flush>
                <thead><tr>
                    <th>Name</th><th>Kind</th><th class="text-right">Uptime</th>
                    <th class="text-right">Downtime</th><th class="text-right">Excluded</th>
                    <th class="text-right">Incidents</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="font-medium text-slate-900">
                                @if ($r['kind'] === 'monitor')
                                    <a href="{{ route('monitors.show', $r['id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a>
                                @else
                                    <a href="{{ route('hosts.show', $r['id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a>
                                @endif
                                @unless ($r['has_data'])
                                    <span class="ml-1 text-xs text-slate-400">partial period</span>
                                @endunless
                            </td>
                            <td class="text-slate-500">{{ ucfirst($r['kind']) }}</td>
                            <td class="text-right tabular font-medium {{ $r['uptime'] >= 99.9 ? 'text-emerald-600' : ($r['uptime'] >= 99 ? 'text-amber-600' : 'text-rose-600') }}">
                                {{ number_format($r['uptime'], 3) }}%
                            </td>
                            <td class="text-right tabular text-slate-500">{{ $r['downtime_seconds'] ? gmdate('H:i:s', $r['downtime_seconds'] % 86400) : '0' }}</td>
                            <td class="text-right tabular text-slate-400">{{ $r['excluded_seconds'] ? gmdate('H:i:s', $r['excluded_seconds'] % 86400) : '0' }}</td>
                            <td class="text-right tabular text-slate-500">{{ $r['incidents'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
        <p class="mt-3 text-xs text-slate-400">
            Excluded time fell inside a downtime window and is removed from the calculation rather
            than counted as either up or down. "Partial period" means the monitor or host did not
            exist for the whole window.
        </p>
    @endif
</x-layouts.app>
