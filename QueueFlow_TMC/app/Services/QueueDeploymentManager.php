<?php

namespace App\Services;

use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages the per-office 100-slot batch lifecycle:
 * OPEN -> FULL/CLOSED -> DEPLOYED -> COMPLETED -> (new) OPEN
 */
class QueueDeploymentManager
{
    /**
     * Mark the office batch as ready for service processing.
     *
     * When the office has no active batch yet, a fresh batch is opened with
     * the requested slot count and LEFT OPEN for intake: deploying the day's
     * first batch is what puts numbers on the counter for students (via the
     * API and the Flutter app), so closing intake here would refuse every
     * ticket until somebody completed a batch nobody was ever allowed into.
     *
     * When an open batch already exists, it is moved to DEPLOYED instead:
     * intake closes and the counter starts serving the waiting line. The
     * fresh batch continues the day's sequence (first of the day = #1,
     * then #2, #3, ...).
     */
    public function deploy(Office $office, ?int $slots = null): QueueDeployment
    {
        $deployment = QueueDeployment::activeFor($office);

        if (! $deployment) {
            return QueueDeployment::startFor($office, $slots);
        }

        if ($deployment->status !== QueueDeployment::STATUS_COMPLETED) {
            $deployment->update([
                'status' => QueueDeployment::STATUS_DEPLOYED,
                'closed_at' => $deployment->closed_at ?? now(),
            ]);
        }

        return $deployment;
    }

    /**
     * Close the active batch: it stops accepting new queue tickets while
     * every ticket already issued stays exactly as it is for staff to keep
     * serving. Unlike close(), no new batch opens here — staff deploy the
     * next batch explicitly when ready.
     */
    public function closeBatch(Office $office): QueueDeployment
    {
        $deployment = QueueDeployment::openFor($office) ?: QueueDeployment::activeFor($office);

        if (! $deployment || $deployment->status === QueueDeployment::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'deployment' => "{$office->name} has no active batch to close.",
            ]);
        }

        if ($deployment->status === QueueDeployment::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'deployment' => "{$office->name} batch #{$deployment->batch_no} is already closed.",
            ]);
        }

        $deployment->update([
            'status' => QueueDeployment::STATUS_CLOSED,
            'closed_at' => $deployment->closed_at ?? now(),
        ]);

        return $deployment;
    }

    /**
     * Close the current batch: the finished batch is sent to history
     * (marked completed) and a fresh batch opens with the number reset to 1.
     */
    public function close(Office $office): QueueDeployment
    {
        $deployment = QueueDeployment::openFor($office) ?: QueueDeployment::activeFor($office);

        if (! $deployment) {
            throw ValidationException::withMessages([
                'deployment' => "{$office->name} has no active batch to reset.",
            ]);
        }

        if ($deployment->status !== QueueDeployment::STATUS_COMPLETED) {
            $deployment->update([
                'status' => QueueDeployment::STATUS_COMPLETED,
                'closed_at' => $deployment->closed_at ?? now(),
                'completed_at' => now(),
            ]);
        }

        return QueueDeployment::restartResetFor($office);
    }

    /**
     * Mark the batch as fully served/completed and open a fresh batch.
     */
    public function completeAndRestart(Office $office): QueueDeployment
    {
        $deployment = QueueDeployment::activeFor($office);

        if ($deployment) {
            $deployment->update([
                'status' => QueueDeployment::STATUS_COMPLETED,
                'closed_at' => $deployment->closed_at ?? now(),
                'completed_at' => now(),
            ]);
        }

        return QueueDeployment::startFor($office);
    }

    /**
     * Open a fresh batch. Only allowed when no batch exists yet, the
     * previous batch completed, or the previous batch was closed by staff
     * (a closed batch takes the next sequential number with the requested
     * slot count).
     */
    public function start(Office $office, ?int $slots = null): QueueDeployment
    {
        $active = QueueDeployment::activeFor($office);

        if ($active && ! in_array($active->status, [
            QueueDeployment::STATUS_COMPLETED,
            QueueDeployment::STATUS_CLOSED,
        ], true)) {
            throw ValidationException::withMessages([
                'deployment' => 'Finish the current batch (close it or complete all serves) before opening a new one.',
            ]);
        }

        return QueueDeployment::startFor($office, $slots);
    }

    /**
     * Reset the office batch number to Batch 1 for closing/offline.
     *
     * Used when staff close the office for the day: today's non-completed
     * batches are sent to history (marked completed, tickets untouched —
     * they keep pointing at their own deployment_id) and the displayed
     * sequence restarts, so the next deployment is Batch 1, then #2, #3,
     * ... instead of continuing past the archived numbers. History rows
     * keep their stored numbers; duplicate displayed numbers across
     * history are safe because the unique index on (office_id, batch_no)
     * was dropped and queue numbers are unique per deployment
     * (deployment_id), never per batch_no.
     *
     * No fresh batch is opened here: the dashboard shows no active batch
     * and the deploy control offers Batch 1, so staff choose when intake
     * resumes. Never touches offices.status (an offline office stays
     * offline), tickets, or any other office. Repeated resets are
     * harmless: with nothing left to complete they only re-anchor the
     * already-reset sequence.
     *
     * @return int the next batch number (1 after a reset).
     */
    public function resetBatchNumber(Office $office): int
    {
        return DB::transaction(function () use ($office) {
            QueueDeployment::where('office_id', $office->id)
                ->whereDate('created_at', today())
                ->where('status', '!=', QueueDeployment::STATUS_COMPLETED)
                ->get()
                ->each(function (QueueDeployment $deployment) {
                    $deployment->update([
                        'status' => QueueDeployment::STATUS_COMPLETED,
                        'closed_at' => $deployment->closed_at ?? now(),
                        'completed_at' => now(),
                    ]);
                });

            // Restart the displayed sequence after the archived rows: later
            // batches count from here, not from the history numbers.
            $latestId = (int) QueueDeployment::where('office_id', $office->id)->max('id');

            if ($latestId > 0) {
                QueueDeployment::setSequenceAnchor($office, $latestId);
            }

            return QueueDeployment::nextBatchNoFor($office);
        });
    }

    /**
     * Auto-promote a deployment to COMPLETED when every ticket in the batch
     * has been served. Called after a ticket is marked served/cancelled.
     */
    public function refreshAfterService(QueueTicket $ticket): void
    {
        $deployment = $ticket->deployment;

        if (! $deployment || $deployment->status === QueueDeployment::STATUS_COMPLETED) {
            return;
        }

        $outstanding = $deployment->tickets()
            ->whereIn('status', [
                QueueTicket::STATUS_WAITING,
                QueueTicket::STATUS_SERVING,
            ])
            ->exists();

        if (! $outstanding) {
            $deployment->update([
                'status' => QueueDeployment::STATUS_COMPLETED,
                'closed_at' => $deployment->closed_at ?? now(),
                'completed_at' => now(),
            ]);
        }
    }
}