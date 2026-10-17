<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes the staff cancellation alert for a ticket that just moved to
 * `cancelled`.
 *
 * Called only AFTER the status update has succeeded, so a failed or
 * refused cancellation never produces a row. Idempotent: a second call
 * for the same ticket returns the existing row instead of stacking a
 * duplicate, which is what makes repeated Cancel taps and retried
 * POSTs safe.
 */
class TicketCancellationNotifier
{
    public static function notify(QueueTicket $ticket, ?User $actor = null, ?string $reason = null): ?StaffNotification
    {
        if ($ticket->status !== QueueTicket::STATUS_CANCELLED) {
            return null;
        }

        $ticket->loadMissing(['office', 'student']);

        $office = $ticket->office;
        $student = $ticket->student ?: $actor;

        if (! $office) {
            return null;
        }

        $cancelledAt = $ticket->cancelled_at ?: ($ticket->updated_at ?: now());
        $reason = $reason !== null ? trim($reason) : trim((string) ($ticket->cancel_reason ?? ''));

        if ($reason === '') {
            $reason = null;
        }

        $studentName = $student?->name ?? 'A student';
        $studentIdNo = $student?->student_id;
        $officeName = $office->name;
        $officeCode = $office->code;

        $message = sprintf(
            '%s — Student ID: %s cancelled ticket %s at the %s.',
            StaffNotification::TITLE_TICKET_CANCELLED,
            $studentIdNo ?: $studentName,
            $ticket->queue_number,
            $officeName
        );

        if ($reason !== null) {
            $message .= ' Reason: '.$reason;
        }

        return DB::transaction(function () use ($ticket, $actor, $office, $cancelledAt, $studentName, $studentIdNo, $officeName, $officeCode, $message, $reason) {
            $existing = StaffNotification::where('ticket_id', $ticket->id)
                ->where('type', StaffNotification::TYPE_TICKET_CANCELLED)
                ->first();

            if ($existing) {
                return $existing;
            }

            return StaffNotification::create([
                'type' => StaffNotification::TYPE_TICKET_CANCELLED,
                'office_id' => $office->id,
                'ticket_id' => $ticket->id,
                'triggered_by' => $actor?->id ?? $ticket->user_id,
                'title' => StaffNotification::TITLE_TICKET_CANCELLED,
                'message' => $message,
                'student_name' => $studentName,
                'student_id_no' => $studentIdNo,
                'queue_number' => $ticket->queue_number,
                'office_name' => $officeName,
                'office_code' => $officeCode,
                'purpose' => $ticket->purpose,
                'priority' => $ticket->priority ?? QueueTicket::PRIORITY_REGULAR,
                'reason' => $reason,
                'cancelled_at' => $cancelledAt,
            ]);
        });
    }
}
