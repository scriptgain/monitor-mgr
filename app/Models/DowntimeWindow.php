<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A planned window during which alerts are held back.
 *
 * Checks keep running and incidents keep opening. Only delivery stops, and the
 * incident records that it was suppressed so the history says "we knew, we were
 * working on it" rather than going quiet with no explanation.
 */
class DowntimeWindow extends Model
{
    use OwnedByUser;

    public const KINDS = ['once' => 'One Off', 'weekly' => 'Weekly'];

    public const DAYS = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    protected $fillable = [
        'user_id', 'name', 'monitor_id', 'monitored_host_id', 'host_group_id', 'kind',
        'starts_at', 'ends_at', 'days_of_week', 'start_time', 'end_time', 'is_enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'days_of_week' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'monitored_host_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(HostGroup::class, 'host_group_id');
    }

    /** Null subject means the window covers everything. */
    public function subjectName(): string
    {
        return $this->monitor?->name ?? $this->host?->name ?? $this->group?->name ?? 'Everything';
    }

    /** Is this window covering the given moment? */
    public function coversTime(?Carbon $at = null): bool
    {
        $at ??= now();
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->kind === 'once') {
            return $this->starts_at && $this->ends_at
                && $at->betweenIncluded($this->starts_at, $this->ends_at);
        }

        if (! in_array((int) $at->dayOfWeek, array_map('intval', (array) $this->days_of_week), true)) {
            return false;
        }
        if (! $this->start_time || ! $this->end_time) {
            return false;
        }

        $now = $at->format('H:i:s');
        $from = $this->normalizeTime($this->start_time);
        $to = $this->normalizeTime($this->end_time);

        // A window that ends before it starts runs over midnight, which is when
        // most maintenance actually happens.
        return $from <= $to
            ? ($now >= $from && $now <= $to)
            : ($now >= $from || $now <= $to);
    }

    /**
     * How many seconds between `from` and `to` this window covers.
     *
     * `coversTime()` answers "right now", which is all alert suppression needs.
     * Availability needs the overlap with an arbitrary past range, and for a
     * weekly rule that recurs and can run over midnight that is a different
     * question: the window may open and close several times inside the range.
     *
     * Walked day by day rather than solved arithmetically. A weekly window is at
     * most a few hundred iterations over a ninety day report, and the closed
     * form has to special case the midnight wrap, a range that starts mid-window,
     * and a range shorter than one occurrence. The loop gets all three right by
     * construction.
     */
    public function overlapSeconds(Carbon $from, Carbon $to): int
    {
        if (! $this->is_enabled || $to->lte($from)) {
            return 0;
        }

        // Whole seconds, for the same reason AvailabilityCalculator normalizes:
        // `now()` has microseconds and stored timestamps do not.
        $from = $from->copy()->startOfSecond();
        $to = $to->copy()->startOfSecond();

        if ($this->kind === 'once') {
            if (! $this->starts_at || ! $this->ends_at) {
                return 0;
            }

            return self::intersect($from, $to, $this->starts_at, $this->ends_at);
        }

        if (! $this->start_time || ! $this->end_time) {
            return 0;
        }

        $days = array_map('intval', (array) $this->days_of_week);
        if ($days === []) {
            return 0;
        }

        $seconds = 0;
        // Start a day early: a window that opened yesterday evening and runs
        // past midnight still covers the beginning of this range.
        $cursor = $from->copy()->subDay()->startOfDay();
        $limit = $to->copy()->endOfDay();

        while ($cursor->lte($limit)) {
            if (in_array((int) $cursor->dayOfWeek, $days, true)) {
                $open = $cursor->copy()->setTimeFromTimeString(substr((string) $this->start_time, 0, 8));
                $close = $cursor->copy()->setTimeFromTimeString(substr((string) $this->end_time, 0, 8));
                if ($close->lte($open)) {
                    $close->addDay(); // Runs over midnight into the next day.
                }
                $seconds += self::intersect($from, $to, $open, $close);
            }
            $cursor->addDay();
        }

        return min($seconds, (int) $from->diffInSeconds($to));
    }

    /** Seconds shared by two ranges. */
    private static function intersect(Carbon $aFrom, Carbon $aTo, Carbon $bFrom, Carbon $bTo): int
    {
        $start = $aFrom->gt($bFrom) ? $aFrom : $bFrom;
        $end = $aTo->lt($bTo) ? $aTo : $bTo;

        return $end->gt($start) ? (int) $start->diffInSeconds($end) : 0;
    }

    /** Does this window apply to the thing the incident is about? */
    public function coversSubject(Incident $incident): bool
    {
        if ($this->monitor_id === null && $this->monitored_host_id === null && $this->host_group_id === null) {
            return true;
        }
        if ($this->monitor_id !== null) {
            return $this->monitor_id === $incident->monitor_id;
        }
        if ($this->monitored_host_id !== null) {
            return $this->monitored_host_id === $incident->monitored_host_id;
        }

        // A group window covers a host incident when that host is a member. It
        // never covers a monitor incident: monitors are not in host groups.
        return $incident->monitored_host_id !== null
            && $this->group?->hosts()->whereKey($incident->monitored_host_id)->exists();
    }

    /**
     * The window in force for an incident right now, or null.
     *
     * Only enabled windows are loaded, and the whole set is small enough that
     * filtering in PHP beats trying to express a recurring weekly window that
     * wraps midnight in portable SQL.
     */
    public static function activeFor(Incident $incident, ?Carbon $at = null): ?self
    {
        foreach (self::query()->where('is_enabled', true)->get() as $window) {
            if ($window->coversSubject($incident) && $window->coversTime($at)) {
                return $window;
            }
        }

        return null;
    }

    /** Human summary for the list. */
    public function schedule(): string
    {
        if ($this->kind === 'once') {
            if (! $this->starts_at || ! $this->ends_at) {
                return 'Not scheduled';
            }

            return $this->starts_at->format('M j, Y g:i A').' to '.$this->ends_at->format('M j, Y g:i A');
        }

        $days = array_map(fn ($d) => self::DAYS[(int) $d] ?? $d, (array) $this->days_of_week);
        $when = $days ? implode(', ', $days) : 'No days';

        return $when.' '.substr((string) $this->start_time, 0, 5).' to '.substr((string) $this->end_time, 0, 5);
    }

    private function normalizeTime(string $time): string
    {
        return substr($time.':00:00', 0, 8);
    }
}
