<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueueTicket extends Model
{
    use HasFactory;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_SERVING = 'serving';
    public const STATUS_SERVED = 'served';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_SERVING,
        self::STATUS_SERVED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Terminal states: the ticket left the live queue and must never return
     * to waiting/serving via a refresh, a poll, or a new batch. History,
     * reports and the student app all read these as finished.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_SERVED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /** Live states that still occupy a queue slot today. */
    public const ACTIVE_STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_SERVING,
    ];

    public const PRIORITY_REGULAR = 'regular';
    public const PRIORITY_PWD = 'pwd';

    public const PRIORITIES = [
        self::PRIORITY_REGULAR,
        self::PRIORITY_PWD,
    ];

    public const DEFAULT_CALL_DURATION_MINUTES = 5;
    public const MIN_CALL_DURATION_MINUTES = 1;
    public const MAX_CALL_DURATION_MINUTES = 60;

    protected $fillable = [
        'queue_number',
        'office_id',
        'service_id',
        'station_id',
        'course_id',
        'year_level',
        'priority',
        'priority_evidence',
        'deployment_id',
        'user_id',
        'served_by',
        'purpose',
        'status',
        'called_at',
        'call_duration_minutes',
        'expires_at',
        'arrived_at',
        'cancelled_at',
        'cancel_reason',
        'served_at',
        'no_show_at',
        'no_show_by',
        'recall_count',
        'last_recalled_at',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'expires_at' => 'datetime',
        'arrived_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'served_at' => 'datetime',
        'no_show_at' => 'datetime',
        'last_recalled_at' => 'datetime',
        'call_duration_minutes' => 'integer',
        'recall_count' => 'integer',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function service()
    {
        return $this->belongsTo(OfficeService::class, 'service_id');
    }

    public function station()
    {
        return $this->belongsTo(OfficeStation::class, 'station_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function deployment()
    {
        return $this->belongsTo(QueueDeployment::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function servedByStaff()
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    public function noShowByStaff()
    {
        return $this->belongsTo(User::class, 'no_show_by');
    }

    public function isPwd(): bool
    {
        return $this->priority === self::PRIORITY_PWD;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function isServing(): bool
    {
        return $this->status === self::STATUS_SERVING;
    }

    public function isServed(): bool
    {
        return $this->status === self::STATUS_SERVED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isActiveSlot(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Normalize a staff-supplied call duration to a valid minute value.
     *
     * Returns null when the input is missing or invalid so callers can
     * reject it rather than guessing. The bounds (1-60, default 5) are the
     * contract the staff console, the API and Flutter all share.
     */
    public static function normalizeCallDuration(mixed $value, bool $useDefault = true): ?int
    {
        if ($value === null || $value === '') {
            return $useDefault ? self::DEFAULT_CALL_DURATION_MINUTES : null;
        }

        if (is_bool($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        // Reject floats-as-strings like "4.5": they truncate to a value the
        // staff member did not pick.
        if ((float) $value != (float) $minutes) {
            return null;
        }

        if ($minutes < self::MIN_CALL_DURATION_MINUTES || $minutes > self::MAX_CALL_DURATION_MINUTES) {
            return null;
        }

        return $minutes;
    }

    public static function callDurationRules(): array
    {
        return [
            'nullable',
            'integer',
            'min:'.self::MIN_CALL_DURATION_MINUTES,
            'max:'.self::MAX_CALL_DURATION_MINUTES,
        ];
    }

    /**
     * Whether a serving ticket has passed its staff-set deadline.
     *
     * Only meaningful while serving with an expiry stamp. A ticket without
     * expires_at (issued before this feature) never counts as expired so
     * old rows cannot wedge the queue. A ticket whose arrival was confirmed
     * (arrived_at set, deadline cleared) never counts as expired either:
     * stopping the timer is a promise the student is at the window.
     */
    public function isExpired(): bool
    {
        if ($this->status !== self::STATUS_SERVING || ! $this->expires_at) {
            return false;
        }

        if ($this->arrived_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Seconds left until expiry, 0 when expired, arrived, or not serving.
     *
     * An arrived ticket reads 0 because its countdown was stopped on
     * purpose, not because it ran out.
     */
    public function remainingSeconds(): int
    {
        if ($this->status !== self::STATUS_SERVING || ! $this->expires_at) {
            return 0;
        }

        if ($this->arrived_at) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * Whether staff confirmed the student reached the window.
     *
     * Arrival keeps status=serving (the ticket is still workable) and only
     * clears the deadline, so this flag is the display state the staff
     * console and Flutter read as "Arrived / Present".
     */
    public function isArrived(): bool
    {
        return $this->status === self::STATUS_SERVING && $this->arrived_at !== null;
    }

    public function hasArrived(): bool
    {
        return $this->isArrived();
    }

    /**
     * Effective call duration for display: stored value, else the default.
     */
    public function effectiveCallDuration(): int
    {
        $stored = (int) ($this->call_duration_minutes ?? 0);

        if ($stored >= self::MIN_CALL_DURATION_MINUTES && $stored <= self::MAX_CALL_DURATION_MINUTES) {
            return $stored;
        }

        return self::DEFAULT_CALL_DURATION_MINUTES;
    }

    /**
     * Whether this ticket carries verified PWD evidence.
     *
     * Verified means all three: the priority flag is PWD, a proof path is
     * recorded, and the file is actually on disk. A missing file (deleted
     * by hand, failed upload) reads as unverified rather than trusted,
     * reusing the same evidence check the staff evidence stream uses.
     */
    public function hasVerifiedEvidence(): bool
    {
        if (! $this->isPwd() || blank($this->priority_evidence)) {
            return false;
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->exists($this->priority_evidence);
    }

    /**
     * Next queue number for a deployment, e.g. REG-001..REG-100.
     *
     * The sequence is scoped to the batch so numbers never duplicate across
     * active deployments and restart cleanly for each new batch.
     *
     * Returns null when the deployment has already reached its slot limit.
     */
    public static function nextNumberForDeployment(QueueDeployment $deployment): ?string
    {
        $used = static::where('deployment_id', $deployment->id)
            ->orderByDesc('id')
            ->value('queue_number');

        $sequence = 1;

        if ($used && preg_match('/-(\d+)$/', $used, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        if ($sequence > $deployment->max_slots) {
            return null;
        }

        $office = $deployment->office;

        return sprintf('%s-%03d', strtoupper($office->code), $sequence);
    }
}