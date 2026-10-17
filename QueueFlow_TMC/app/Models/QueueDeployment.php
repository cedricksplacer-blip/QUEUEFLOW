<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueueDeployment extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_FULL = 'full';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_DEPLOYED = 'deployed';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_FULL,
        self::STATUS_CLOSED,
        self::STATUS_DEPLOYED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'office_id',
        'batch_no',
        'status',
        'max_slots',
        'opened_at',
        'closed_at',
        'completed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function tickets()
    {
        return $this->hasMany(QueueTicket::class, 'deployment_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isReadyForService(): bool
    {
        return in_array($this->status, [
            self::STATUS_FULL,
            self::STATUS_CLOSED,
        ], true);
    }

    public function submittedCount(): int
    {
        return $this->tickets()->count();
    }

    public function servedCount(): int
    {
        return $this->tickets()
            ->where('status', QueueTicket::STATUS_SERVED)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Deployment helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Active deployment (anything not completed) for an office, scoped to
     * today so a previous date's batch does not carry into a new day.
     */
    public static function activeFor(Office $office): ?self
    {
        return self::where('office_id', $office->id)
            ->where('status', '!=', self::STATUS_COMPLETED)
            ->whereDate('created_at', today())
            ->latest('id')
            ->first();
    }

    /**
     * The CURRENTLY OPEN deployment (accepting new tickets) for an office,
     * scoped to today for the same reason.
     */
    public static function openFor(Office $office): ?self
    {
        return self::where('office_id', $office->id)
            ->where('status', self::STATUS_OPEN)
            ->whereDate('created_at', today())
            ->latest('id')
            ->first();
    }

    /**
     * Create a brand-new per-batch deployment for an office.
     *
     * Batch numbers count sequentially within the day: the first batch of
     * a day starts at #1, and each subsequent batch increments, so numbers
     * do not drift upward based on previous days' history. After a staff
     * "Reset Batch Number", counting restarts from the reset point (see
     * sequenceAnchorId()), so the next batches are #2, #3, ... instead of
     * continuing past the archived numbers.
     *
     * @param  int|null  $maxSlots  queue slots for this batch; falls back
     *                              to the office default when omitted.
     */
    public static function startFor(Office $office, ?int $maxSlots = null): self
    {
        $batchNo = self::nextBatchNoFor($office);

        $slots = $maxSlots !== null ? max(1, $maxSlots) : ((int) $office->max_slots ?: 100);

        return self::create([
            'office_id' => $office->id,
            'batch_no' => $batchNo,
            'status' => self::STATUS_OPEN,
            'max_slots' => $slots,
            'opened_at' => now(),
        ]);
    }

    /**
     * Next batch number for an office.
     *
     * Normally the day's max plus one. When staff reset the sequence, only
     * batches created after the reset point count, so numbering restarts
     * at 1, 2, 3, ... History rows keep their stored numbers — only the
     * computation of the NEXT number is scoped. Tickets are unaffected:
     * queue numbers are unique per deployment (deployment_id), never per
     * batch_no.
     */
    public static function nextBatchNoFor(Office $office): int
    {
        $query = self::where('office_id', $office->id)
            ->whereDate('created_at', today());

        if ($anchor = self::sequenceAnchorId($office)) {
            $query->where('id', '>', $anchor);
        }

        return ((int) $query->max('batch_no')) + 1;
    }

    /**
     * Deployment id the current batch sequence counts after, set by a staff
     * batch-number reset. Null means legacy behaviour (max over the day).
     *
     * Stored per office in settings (like the per-office call timeout), so
     * it survives refreshes and logins. A stale anchor is harmless: rows
     * created on later days always have larger ids, so day-scoped queries
     * behave exactly as without it.
     */
    public static function sequenceAnchorId(Office $office): ?int
    {
        $id = (int) (Setting::get('office_' . $office->id . '_batch_seq_anchor') ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Point the office batch sequence after a deployment (batches created
     * later restart numbering from 1).
     */
    public static function setSequenceAnchor(Office $office, int $deploymentId): void
    {
        Setting::set('office_' . $office->id . '_batch_seq_anchor', (string) $deploymentId);
    }

    /**
     * Start a brand-new batch whose number RESETS to 1.
     *
     * Used when an office closes the current batch: the finished batch is
     * sent to history (completed) and this fresh batch opens at #1.
     */
    public static function restartResetFor(Office $office): self
    {
        return self::create([
            'office_id' => $office->id,
            'batch_no' => 1,
            'status' => self::STATUS_OPEN,
            'max_slots' => (int) $office->max_slots ?: 100,
            'opened_at' => now(),
        ]);
    }

    /**
     * Whether an office is inside its configured acceptance window.
     */
    public static function officeWindowIsOpen(Office $office): bool
    {
        $opening = $office->opening_time;
        $closing = $office->closing_time;

        if (blank($opening) || blank($closing)) {
            return true;
        }

        $now = now()->format('H:i');
        $openingText = \Carbon\Carbon::parse($opening)->format('H:i');
        $closingText = \Carbon\Carbon::parse($closing)->format('H:i');

        return $now >= $openingText && $now <= $closingText;
    }

    /**
     * Try to fetch (or lazily create) an OPEN deployment for ticket creation.
     *
     * Returns null when the office is outside its acceptance window or when
     * no deployment can be opened right now. A staff-closed batch stays
     * closed: only an explicit deploy/start opens the next batch, so intake
     * never resumes behind the counter's back.
     */
    public static function openableFor(Office $office): ?self
    {
        $open = self::openFor($office);

        if ($open) {
            return $open;
        }

        // An existing active deployment blocks a new ticket batch.
        $active = self::activeFor($office);
        if ($active && ! $active->isReadyForService()) {
            return null;
        }

        // Staff closed the batch: hold it closed until they deploy again.
        if ($active && $active->status === self::STATUS_CLOSED) {
            return null;
        }

        if (! self::officeWindowIsOpen($office)) {
            // Auto-mark any ready deployment as closed when the window lapsed.
            if ($active && $active->status === self::STATUS_OPEN) {
                $active->update([
                    'status' => self::STATUS_CLOSED,
                    'closed_at' => $active->closed_at ?? now(),
                ]);
            }

            return null;
        }

        return self::startFor($office);
    }
}