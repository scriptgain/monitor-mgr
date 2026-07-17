<x-layouts.app title="Hosts">
    <x-page-header title="Hosts" icon="server" subtitle="Live CPU, Memory, Disk And Load From Your Enrolled Agents.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('hosts.create') }}">Add Host</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $online = $hosts->filter(fn ($h) => $h->effective_status === 'online')->count();
        $offline = $hosts->filter(fn ($h) => $h->effective_status === 'offline')->count();
        $pending = $hosts->filter(fn ($h) => $h->effective_status === 'pending')->count();
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Online" :value="$online" icon="check-circle" />
        <x-stat label="Offline" :value="$offline" icon="x-circle" />
        <x-stat label="Pending" :value="$pending" icon="clock" />
    </div>

    @if ($hosts->isEmpty())
        <x-card>
            <x-empty-state icon="server" title="No Hosts Yet"
                description="Add a host to get a one-time enrollment token and a one-line install command for the monitoring agent.">
                <x-slot:action>
                    <x-button icon="plus" href="{{ route('hosts.create') }}">Add Host</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <x-card flush>
            <x-table>
                <thead>
                    <tr>
                        <th>Host</th>
                        <th>Status</th>
                        <th>CPU</th>
                        <th>Memory</th>
                        <th>Disk</th>
                        <th>Last Seen</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hosts as $host)
                        @php
                            $m = $host->latestMetric;
                            $st = $host->effective_status;
                            $badge = ['online' => 'success', 'offline' => 'danger', 'pending' => 'warn'][$st] ?? 'neutral';
                            $cpu = $m?->cpu_pct ?? 0;
                            $mem = $m?->memPct() ?? 0;
                            $disk = $m?->diskPct() ?? 0;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('hosts.show', $host) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $host->name }}</a>
                                @if ($host->hostname)<span class="block text-xs text-slate-400">{{ $host->hostname }}</span>@endif
                            </td>
                            <td><x-badge :color="$badge" dot>{{ ucfirst($st) }}</x-badge></td>
                            <td>
                                @if ($m)<x-meter :pct="$cpu" />@else <span class="text-slate-300">&mdash;</span>@endif
                            </td>
                            <td>
                                @if ($m)<x-meter :pct="$mem" />@else <span class="text-slate-300">&mdash;</span>@endif
                            </td>
                            <td>
                                @if ($m)<x-meter :pct="$disk" />@else <span class="text-slate-300">&mdash;</span>@endif
                            </td>
                            <td class="text-slate-500">{{ $host->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="text-right">
                                <x-button variant="secondary" size="sm" icon="eye" href="{{ route('hosts.show', $host) }}">View</x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </x-card>
    @endif
</x-layouts.app>
