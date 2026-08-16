<x-layouts.app :title="'Incident #' . $incident->id">
    <x-page-header :title="'Incident #' . $incident->id" icon="warning"
        :subtitle="$incident->subjectName() . ' · ' . $incident->severityLabel()"
        :back="['href' => route('incidents.index'), 'label' => 'Incidents']">
        <x-slot:actions>
            @if ($incident->isOpen())
                @unless ($incident->isAcknowledged())
                    <form method="POST" action="{{ route('incidents.ack', $incident) }}">
                        @csrf
                        <x-button type="submit" variant="secondary" icon="check">Acknowledge</x-button>
                    </form>
                @endunless
                <form method="POST" action="{{ route('incidents.resolve', $incident) }}">
                    @csrf
                    <x-button type="submit" icon="check-circle">Resolve</x-button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Details">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ $incident->monitor_id ? 'Monitor' : 'Host' }}</dt><dd class="text-slate-900">
                        @if ($incident->subjectRoute())
                            <a href="{{ $incident->subjectRoute() }}" class="text-brand-700 hover:underline">{{ $incident->subjectName() }}</a>
                        @else
                            {{ $incident->subjectName() }}
                        @endif
                    </dd></div>
                    <div><dt class="text-slate-500">Severity</dt><dd><x-badge :color="$incident->severityColor()">{{ $incident->severityLabel() }}</x-badge></dd></div>
                    @if ($incident->trigger)
                        <div><dt class="text-slate-500">Trigger</dt><dd class="text-slate-900"><a href="{{ route('triggers.edit', $incident->trigger) }}" class="text-brand-700 hover:underline">{{ $incident->trigger->name }}</a> <span class="text-slate-500">({{ $incident->trigger->condition() }})</span></dd></div>
                    @endif
                    <div><dt class="text-slate-500">Status</dt><dd>@if ($incident->isOpen())<x-badge color="danger" dot>Open</x-badge>@else<x-badge color="success">Resolved</x-badge>@endif</dd></div>
                    <div><dt class="text-slate-500">Started</dt><dd class="text-slate-900">{{ $incident->started_at->format('M j, Y g:i:s A') }}</dd></div>
                    <div><dt class="text-slate-500">Resolved</dt><dd class="text-slate-900">{{ $incident->resolved_at ? $incident->resolved_at->format('M j, Y g:i:s A') : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Duration</dt><dd class="text-slate-900">{{ $incident->duration_seconds ? gmdate('H:i:s', $incident->duration_seconds) : ($incident->isOpen() ? 'Ongoing' : '—') }}</dd></div>
                    <div><dt class="text-slate-500">Acknowledged</dt><dd class="text-slate-900">{{ $incident->acknowledged_at ? $incident->acknowledged_at->diffForHumans() : 'Not yet' }}</dd></div>
                </dl>
                @if ($incident->cause)
                    <div class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-600">{{ $incident->cause }}</div>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
