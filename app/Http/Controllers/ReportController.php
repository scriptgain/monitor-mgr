<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\MonitoredHost;
use App\Services\AvailabilityCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Availability reporting.
 *
 * The first page in this product that answers "how did we do last month" rather
 * than "what is broken right now".
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        [$period, $from, $to] = $this->window($request);
        $user = $request->user();

        $monitors = Monitor::visibleTo($user)->orderBy('name')->get()
            ->map(fn (Monitor $m) => $this->row($m, 'monitor', $from, $to));

        $hosts = MonitoredHost::visibleTo($user)->orderBy('name')->get()
            ->map(fn (MonitoredHost $h) => $this->row($h, 'host', $from, $to));

        $rows = $monitors->concat($hosts);
        $withData = $rows->where('has_data', true);

        return view('reports.index', [
            'rows' => $rows,
            'period' => $period,
            'periods' => AvailabilityCalculator::PERIOD_LABELS,
            'from' => $from,
            'to' => $to,
            // Averaged only over subjects that existed for the whole window, so
            // one host enrolled yesterday cannot drag the headline number.
            'overall' => $withData->isEmpty() ? null : round($withData->avg('uptime'), 4),
            'totalDowntime' => (int) $rows->sum('downtime_seconds'),
            'totalIncidents' => (int) $rows->sum('incidents'),
        ]);
    }

    /**
     * The same table as a CSV.
     *
     * Streamed, following BackupController::downloadConfig. Nothing here is
     * large enough to need it, but a report someone runs monthly is exactly the
     * thing that quietly grows.
     */
    public function export(Request $request)
    {
        [$period, $from, $to] = $this->window($request);
        $user = $request->user();

        $rows = Monitor::visibleTo($user)->orderBy('name')->get()
            ->map(fn (Monitor $m) => $this->row($m, 'monitor', $from, $to))
            ->concat(
                MonitoredHost::visibleTo($user)->orderBy('name')->get()
                    ->map(fn (MonitoredHost $h) => $this->row($h, 'host', $from, $to))
            );

        $name = Str::slug(config('brand.name') ?: 'monitor').'-availability-'.$period.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Kind', 'Uptime %', 'Downtime (s)', 'Excluded (s)', 'Incidents', 'Complete Data', 'From', 'To']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['name'], $r['kind'], number_format($r['uptime'], 4, '.', ''),
                    $r['downtime_seconds'], $r['excluded_seconds'], $r['incidents'],
                    $r['has_data'] ? 'yes' : 'partial',
                    $from->toDateTimeString(), $to->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function row(Monitor|MonitoredHost $subject, string $kind, $from, $to): array
    {
        return AvailabilityCalculator::for($subject, $from, $to) + [
            'id' => $subject->id,
            'name' => $subject->name,
            'kind' => $kind,
        ];
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} */
    private function window(Request $request): array
    {
        $period = (string) $request->query('period', '30d');
        if (! isset(AvailabilityCalculator::PERIODS[$period])) {
            $period = '30d';
        }
        $to = now();

        return [$period, $to->copy()->subDays(AvailabilityCalculator::PERIODS[$period]), $to];
    }
}
