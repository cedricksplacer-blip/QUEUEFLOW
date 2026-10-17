<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Office extends Model
{
    use HasFactory;

    /**
     * The operational offices the system exposes (the seven Registrar
     * course offices plus Cashier, Admissions, SASO, Scholarship and Clinic).
     */
    public const OPERATIONAL_CODES = [
        'REG-BSIT',
        'REG-BSOA',
        'REG-BAPOLSCI',
        'REG-BACOMM',
        'REG-BEED',
        'REG-BSED',
        'REG-BSCRIM',
        'CASH',
        'ADM',
        'SASO',
        'SCH',
        'CLINIC',
    ];

    /**
     * Whether the office is online and accepting queue tickets.
     *
     * Kept as a string rather than a boolean so the wire value can live in the
     * API payload unchanged and stay readable in the database.
     */
    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    /**
     * The one Registrar campus group: every per-course (`REG-*`) desk plus the
     * legacy undivided `REG` row. Used only to fold the desks together in the
     * admin recent-activity feed; online/offline status is per counter.
     */
    public const REGISTRAR_GROUP = 'registrar';

    /**
     * Maps an office code to its activity-display group.
     *
     * The Registrar is seven separate rows on purpose (each course queue has
     * its own batch, status and staff member). Every other office is its own
     * group. Codes outside this map fall back to the office's own code so a
     * legacy row is never left without a group.
     */
    public static function groupForCode(?string $code): string
    {
        $normalized = strtoupper((string) $code);

        if ($normalized === 'REG' || str_starts_with($normalized, 'REG-')) {
            return self::REGISTRAR_GROUP;
        }

        return $normalized ?: (string) $code;
    }

    protected $fillable = [
        'name',
        'code',
        'description',
        'office_type',
        'location',
        'is_active',
        'status',
        'opening_time',
        'closing_time',
        'max_slots',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function staff()
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_STAFF);
    }

    public function queueTickets()
    {
        return $this->hasMany(QueueTicket::class);
    }

    public function waitingTickets()
    {
        return $this->queueTickets()->where('status', QueueTicket::STATUS_WAITING);
    }

    public function deployments()
    {
        return $this->hasMany(QueueDeployment::class);
    }

    public function activeDeployment()
    {
        return $this->hasOne(QueueDeployment::class)
            ->where('status', '!=', QueueDeployment::STATUS_COMPLETED)
            ->whereDate('created_at', today())
            ->latestOfMany();
    }

    public function openDeployment()
    {
        return $this->hasOne(QueueDeployment::class)
            ->where('status', QueueDeployment::STATUS_OPEN)
            ->whereDate('created_at', today())
            ->latestOfMany();
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function stations()
    {
        return $this->hasMany(OfficeStation::class);
    }

    public function services()
    {
        return $this->hasMany(OfficeService::class);
    }

    /**
     * Maximum number of accepted queue tickets per batch/deployment.
     */
    public function slotLimit(): int
    {
        return (int) $this->max_slots ?: 100;
    }

    /**
     * Per-office call-timeout in minutes (Queue Timeout Duration).
     *
     * Stored in settings as office_{id}_call_timeout so each counter keeps
     * its own value across refreshes and reconnects. Falls back to the
     * shared default (5) when nothing was saved or the saved value is
     * invalid, and never returns an out-of-range value.
     */
    public function callTimeoutMinutes(): int
    {
        $raw = Setting::get('office_'.$this->id.'_call_timeout', (string) QueueTicket::DEFAULT_CALL_DURATION_MINUTES);

        $minutes = QueueTicket::normalizeCallDuration($raw, false);

        return $minutes ?? QueueTicket::DEFAULT_CALL_DURATION_MINUTES;
    }

    /**
     * Persist the per-office call-timeout after validating the range.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setCallTimeoutMinutes(mixed $minutes): int
    {
        $normalized = QueueTicket::normalizeCallDuration($minutes, false);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ]);
        }

        Setting::set('office_'.$this->id.'_call_timeout', (string) $normalized);

        return $normalized;
    }

    /**
     * Offices staff may be assigned to and students may queue at: every
     * active office, whether it belongs to the official structure or was
     * added later through the admin Offices screen. Retired (inactive)
     * rows stay excluded everywhere this is used.
     */
    public function scopeAssignable($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this office is online and accepting queue tickets.
     */
    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    /**
     * The status key for this row.
     *
     * Every office — including each per-course Registrar counter — carries
     * its own online/offline switch, so the key is always this office's own
     * code. A staff sign-in/out flips only the counter they are assigned to.
     */
    public function groupKey(): string
    {
        return strtoupper((string) $this->code);
    }

    /**
     * The activity-display group this row belongs to.
     *
     * The admin activity feed still rolls every per-course Registrar desk
     * into one Registrar group; status is independent per counter, but the
     * recent-activity list reads better folded together.
     */
    public function activityGroupKey(): string
    {
        return self::groupForCode($this->code);
    }

    /**
     * The display name for this office, used on the admin dashboard and the
     * staff console wherever the counter is named.
     */
    public function groupLabel(): string
    {
        return (string) $this->name;
    }

    /**
     * The ids of every office sharing this office's status switch.
     *
     * Always just this office: each Registrar counter keeps its own
     * ONLINE/OFFLINE status, driven by the staff assigned to that counter.
     *
     * @return array<int>
     */
    public function statusGroupIds(): array
    {
        return [$this->id];
    }

    /**
     * Sets [STATUS_ONLINE]/[STATUS_OFFLINE] for this office.
     *
     * Kept under its historical name so every existing caller (admin
     * toggles, staff console, API, sign-in/out presence) keeps working;
     * each Registrar counter now flips alone, leaving its sibling counters
     * untouched. Returns the number of rows changed so a caller can report
     * what actually happened rather than assuming it.
     */
    public function applyStatusToGroup(string $status): int
    {
        if (! in_array($status, [self::STATUS_ONLINE, self::STATUS_OFFLINE], true)) {
            throw new \InvalidArgumentException("Unknown office status: {$status}");
        }

        $ids = $this->statusGroupIds();

        if ($ids === []) {
            return 0;
        }

        return self::query()
            ->whereIn('id', $ids)
            ->update(['status' => $status]);
    }

    /**
     * The codes that belong to the Registrar group.
     *
     * @return array<int, string>
     */
    public static function codesInRegistrarGroup(): array
    {
        return [
            'REG',
            'REG-BSIT',
            'REG-BSOA',
            'REG-BAPOLSCI',
            'REG-BACOMM',
            'REG-BEED',
            'REG-BSED',
            'REG-BSCRIM',
        ];
    }

    /**
     * The sentence a status toggle reports, naming the office it acted on.
     */
    public static function statusMessage(Office $office, string $status): string
    {
        $label = $office->groupLabel();

        return $status === self::STATUS_ONLINE
            ? "{$label} is now online and accepting queue tickets."
            : "{$label} is now offline and will not accept queue tickets.";
    }
}