<?php

namespace App\Services;

use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The counter-window actions: call the next ticket, and finish the current one.
 *
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD
 * These two actions exist in two places already - the Blade staff console
 * (StaffQueueController) and the bearer-token API that the Flutter client
 * calls. They are the same two actions: promote one ticket, close one ticket.
 * If each side kept its own copy, "who is now serving" would be decided
 * slightly differently by the window and by the phone, and the two would
 * disagree in the middle of a live queue. The ordering rule below is therefore
 * defined exactly once.
 *
 * THE ORDERING RULE
 * PWD first, then oldest. `orderByRaw` rather than two columns on `orderBy`
 * because the priority rank has to be a CASE, and because the rank must be
 * computed in SQL: sorting a loaded collection in PHP would re-order a
 * snapshot that another window's `call` had already moved underneath it.
 */
class QueueCounter
{
    /**
     * Promote a waiting ticket to serving - the oldest one, PWD ahead of
     * regular, unless [ticket] names one in particular.
     *
     * The named case is the counter screen's row action: an operator can see
     * the whole waiting list and hand the window to somebody specific rather
     * than to whoever the ordering rule puts first. It is an override of WHICH
     * ticket, never of WHETHER one may be called: the already-serving check
     * below runs either way, so naming a ticket cannot produce a second number
     * at a window that already has one.
     *
     * Returns null - and changes nothing - in either of the two states the
     * counter screen has to be able to explain: a ticket is already at the
     * window, or nobody is waiting. The caller distinguishes them, because the
     * operator message differs ("already has a ticket being served" versus
     * "no waiting tickets") and neither is an error.
     */
    public function callNext(Office $office, User $caller, ?QueueTicket $ticket = null, ?int $durationMinutes = null): ?QueueTicket
    {
        $duration = QueueTicket::normalizeCallDuration($durationMinutes);

        if ($duration === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ]);
        }

        return DB::transaction(function () use ($office, $caller, $ticket, $duration) {
            $alreadyServing = QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->lockForUpdate()
                ->first();

            // An expired call still occupies the window until staff resolve it
            // via Call Again (recall) or No-Show. Auto-releasing here would
            // silently clear the serving position the expiry popup acts on.
            if ($alreadyServing) {
                return null;
            }

            $query = QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_WAITING)
                ->whereDate('created_at', today());

            if ($ticket) {
                // Re-read under the same lock and the same filters as the
                // automatic case, so a stale row id cannot promote a ticket
                // that has since been served, cancelled or moved.
                $ticket = (clone $query)->whereKey($ticket->getKey())->lockForUpdate()->first();
            } else {
                $ticket = $query
                    ->orderByRaw("CASE WHEN priority = 'pwd' THEN 0 ELSE 1 END, created_at ASC")
                    ->lockForUpdate()
                    ->first();
            }

            if ($ticket) {
                $calledAt = now();
                $ticket->update([
                    'status' => QueueTicket::STATUS_SERVING,
                    'served_by' => $caller->id,
                    'called_at' => $calledAt,
                    'call_duration_minutes' => $duration,
                    'expires_at' => (clone $calledAt)->addMinutes($duration),
                    'arrived_at' => null,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'served_at' => null,
                ]);
            }

            return $ticket;
        });
    }

    /**
     * Close out the ticket currently at the window.
     *
     * Two follow-on effects are part of finishing a ticket rather than extra
     * behaviour bolted on here:
     *
     *   1. refreshAfterService() completes the batch once its last ticket is
     *      gone, which is what lets the next batch open.
     *   2. Auto-call. An operator who has asked to be handed the next student
     *      without touching the screen would otherwise have to press the
     *      button, so the setting is honoured inside the same service call.
     *      It is per staff member and per office, and it only fires while the
     *      batch is actually deployed - calling the next number during the
     *      submission period would pull a forward ticket into a window that is
     *      not serving yet.
     */
    public function serve(QueueTicket $ticket, User $staff, Office $office): QueueTicket
    {
        return DB::transaction(function () use ($ticket, $staff, $office) {
            $ticket = QueueTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($ticket->status !== QueueTicket::STATUS_SERVING) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} is not being served.",
                ]);
            }

            // An expired, un-arrived call can never be served directly: staff
            // must Call Again (new deadline) or No-Show it first. The ticket
            // stays in the serving position so the expiry popup can act on it.
            if ($ticket->isExpired()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} already expired. Use Call Again or No-Show instead.",
                ]);
            }

            $ticket->served_at = now();
            $ticket->status = QueueTicket::STATUS_SERVED;
            // The deadline belongs to the call, not the history: once served it
            // is cleared so a served row never reads as "expired".
            $ticket->expires_at = null;
            $ticket->save();

            (new QueueDeploymentManager())->refreshAfterService($ticket);

            if (Setting::get('staff_'.$staff->id.'_auto_call', '0') === '1') {
                $deployment = QueueDeployment::activeFor($office);

                if ($deployment && $deployment->status === QueueDeployment::STATUS_DEPLOYED) {
                    $this->callNext($office, $staff);
                }
            }

            return $ticket;
        });
    }

    /**
     * Confirm the student reached the window and stop the countdown.
     *
     * Keeps status=serving so the ticket stays workable for Mark Served;
     * only the deadline is cleared and arrived_at stamped. Idempotent: a
     * second Stop press returns the same row instead of failing, so a
     * double-click or a retried POST cannot break the ticket. Refuses an
     * already-expired, un-arrived call (it must be no-showed instead) and
     * any ticket that is not serving.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function confirmArrival(QueueTicket $ticket): QueueTicket
    {
        return DB::transaction(function () use ($ticket) {
            $ticket = QueueTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($ticket->status !== QueueTicket::STATUS_SERVING) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} is not being served.",
                ]);
            }

            if ($ticket->arrived_at) {
                return $ticket;
            }

            if ($ticket->isExpired()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} already expired. Mark it as no-show instead.",
                ]);
            }

            $ticket->arrived_at = now();
            $ticket->expires_at = null;
            $ticket->save();

            return $ticket;
        });
    }

    /**
     * Call the same serving ticket again: new server-stamped deadline.
     *
     * Keeps the ticket in the serving position (no duplicate, no requeue).
     * Resets called_at/expires_at from the staff-configured duration,
     * clears any arrival flag, bumps recall_count and stamps
     * last_recalled_at. Idempotent-safe via row lock: two simultaneous
     * recalls serialize and the second simply re-arms from the first's
     * fresh row, never creating a second serving ticket.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function recall(QueueTicket $ticket, User $staff, Office $office, ?int $durationMinutes = null): QueueTicket
    {
        $duration = QueueTicket::normalizeCallDuration(
            $durationMinutes ?? $office->callTimeoutMinutes()
        );

        if ($duration === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $staff, $office, $duration) {
            $ticket = QueueTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $ticket->office_id !== (int) $office->id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} is not assigned to this office.",
                ]);
            }

            if ($ticket->status !== QueueTicket::STATUS_SERVING) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} is {$ticket->status} and cannot be called again.",
                ]);
            }

            if ($ticket->arrived_at) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} already arrived and cannot be called again.",
                ]);
            }

            $calledAt = now();
            $ticket->called_at = $calledAt;
            $ticket->call_duration_minutes = $duration;
            $ticket->expires_at = (clone $calledAt)->addMinutes($duration);
            $ticket->arrived_at = null;
            $ticket->served_by = $staff->id;
            $ticket->recall_count = (int) ($ticket->recall_count ?? 0) + 1;
            $ticket->last_recalled_at = $calledAt;
            // A recalled ticket is explicitly still live: clear any terminal
            // bookkeeping that a previous partial flow may have left.
            $ticket->cancelled_at = null;
            $ticket->cancel_reason = null;
            $ticket->served_at = null;
            $ticket->no_show_at = null;
            $ticket->no_show_by = null;
            $ticket->save();

            return $ticket;
        });
    }

    /**
     * Mark a serving ticket as a terminal No-Show.
     *
     * The ticket leaves the live queue permanently: status becomes no_show,
     * the window frees for the next eligible student, and the row stays in
     * history/reports with its original called_at/expires_at plus no_show_at
     * and no_show_by for audit. Never returns to waiting, never counts as
     * served, and survives new batches (batches never delete tickets).
     * Duplicate clicks serialize on the row lock; the second sees no_show
     * and is refused instead of double-counting.
     */
    public function markNoShow(QueueTicket $ticket, ?User $staff = null): QueueTicket
    {
        return DB::transaction(function () use ($ticket, $staff) {
            $ticket = QueueTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($ticket->status !== QueueTicket::STATUS_SERVING) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} is {$ticket->status}, so it cannot be marked as a no-show.",
                ]);
            }

            // An arrived student is at the window being attended to: sending
            // them to no-show would lose the arrival confirmation.
            if ($ticket->arrived_at) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'ticket' => "Ticket {$ticket->queue_number} already arrived and cannot be marked as a no-show.",
                ]);
            }

            // Idempotent guard: already terminal no-show (e.g. double POST).
            if ($ticket->status === QueueTicket::STATUS_NO_SHOW) {
                return $ticket;
            }

            $ticket->status = QueueTicket::STATUS_NO_SHOW;
            // Preserve called_at / expires_at / call_duration_minutes for the
            // audit trail (original call + deadline). Only the live deadline
            // semantics end here; the values stay readable in history.
            $ticket->arrived_at = null;
            $ticket->no_show_at = now();
            $ticket->no_show_by = $staff?->id ?? $ticket->served_by;
            $ticket->save();

            (new QueueDeploymentManager())->refreshAfterService($ticket);

            return $ticket;
        });
    }

    /**
     * Whether one ticket is genuinely overdue and un-arrived (check only).
     *
     * No longer mutates: an expired call stays serving until staff choose
     * Call Again or No-Show, so the expiry popup and the student's
     * "time expired" state have something to act on. Returns true when the
     * ticket is serving + expired.
     */
    public function releaseIfExpired(QueueTicket $ticket): bool
    {
        $ticket->refresh();

        if ($ticket->status !== QueueTicket::STATUS_SERVING) {
            return false;
        }

        return $ticket->isExpired();
    }

    /**
     * Count this office's overdue serving tickets without moving them.
     *
     * Used by the expiry sweep endpoint and the staff polling feed so the UI
     * can raise the Call Again / No-Show popup. The scheduler calls
     * expireAllOverdue() which shares this non-destructive contract.
     *
     * @return int number of overdue tickets (still serving)
     */
    public function expireOverdue(Office $office): int
    {
        return (int) QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->whereNotNull('expires_at')
            ->whereNull('arrived_at')
            ->where('expires_at', '<=', now())
            ->count();
    }

    /**
     * Count every office's overdue calls without moving them. Used by the
     * scheduler so expiry is observable even when no browser or phone is
     * open, while the resolution stays a staff decision.
     */
    public function expireAllOverdue(): int
    {
        return (int) QueueTicket::where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->whereNotNull('expires_at')
            ->whereNull('arrived_at')
            ->where('expires_at', '<=', now())
            ->count();
    }

    /**
     * Cancel a serving/waiting ticket: terminal, clears the deadline, never
     * counts as a completed service. Records the staff reason and the
     * cancellation timestamp for the activity log.
     *
     * @throws \Illuminate\Validation\ValidationException on duplicate cancel
     */
    public function cancelCall(QueueTicket $ticket, ?string $reason = null): QueueTicket
    {
        if (in_array($ticket->status, [QueueTicket::STATUS_CANCELLED, QueueTicket::STATUS_SERVED, QueueTicket::STATUS_NO_SHOW], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ticket' => "Ticket {$ticket->queue_number} is already {$ticket->status}.",
            ]);
        }

        $reason = $reason !== null ? trim($reason) : null;

        if ($reason !== null && mb_strlen($reason) > 255) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cancel_reason' => 'Cancellation reason may not be longer than 255 characters.',
            ]);
        }

        $ticket->status = QueueTicket::STATUS_CANCELLED;
        $ticket->called_at = null;
        $ticket->expires_at = null;
        $ticket->arrived_at = null;
        $ticket->cancelled_at = now();
        $ticket->cancel_reason = $reason !== null && $reason !== '' ? $reason : null;
        $ticket->save();

        (new QueueDeploymentManager())->refreshAfterService($ticket);

        return $ticket;
    }
}