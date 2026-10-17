<?php

namespace App\Http\Controllers\Api;

use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueCounter;
use App\Services\QueueDeploymentManager;
use App\Services\TicketCancellationNotifier;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The counter window: reading an office's queue, and the four things an
 * operator can do to it.
 *
 * Nothing here decides queue order. callNext() and serve() delegate to
 * App\Services\QueueCounter, which the Blade counter console also uses, and
 * the batch transitions delegate to QueueDeploymentManager, which the admin
 * and staff portals also use. That is the whole point of this controller being
 * thin: there are now three front doors onto the same queue, and they have to
 * agree about what is happening.
 *
 * Every mutating method starts with guardOffice(), so a staff token can only
 * ever touch the one office it is assigned to.
 */
class QueueController extends ApiController
{
    /**
     * GET /api/v1/queues
 *
     * The flat spelling of "show me the queues": every operational office for
     * an admin, the one assigned office for staff. Each entry is the same
     * payload GET /queues/{office} returns, so a client can use either without
     * knowing which one it called.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);

        if ($user->isStaff()) {
            $offices = collect(array_filter([$this->staffOffice($user)]));
        } else {
            $offices = Office::whereIn('code', Office::OPERATIONAL_CODES)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        $queues = $offices->map(fn (Office $office): array => $this->payloadFor($office))->values();

        return $this->json([
            'queues' => $queues,
            'total' => $queues->count(),
        ]);
    }

    /**
     * GET /api/v1/queues/{office}
     *
     * The counter screen's one payload: who is at the window, who is waiting,
     * who was just finished, and the batch they all belong to.
     */
    public function show(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        // No mutation here: an expired call stays serving+expired so the
        // staff popup (Call Again / No-Show) and the student's "time
        // expired" state have something authoritative to read.
        return $this->json($this->payloadFor($office));
    }

    /**
     * POST /api/v1/queues/{office}/call-next
     *
     * `ticket_id` names the number to call, for the counter screen's row
     * action. It chooses WHICH ticket moves, never WHETHER one may: the
     * already-serving rule inside QueueCounter still applies, so a named
     * ticket is refused by the same 422 as an unnamed call when something is
     * already at the window.
     *
     * The 422s here are deliberately distinct messages rather than one generic
     * refusal, because the operator's next action differs: "nobody is waiting"
     * means the queue drained, "already a ticket being served" means they
     * already pressed the button and the answer did not arrive.
     */
    public function callNext(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $request->validate([
            'ticket_id' => ['nullable', 'integer', 'exists:queue_tickets,id'],
            'call_duration_minutes' => ['nullable', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        $durationInput = $request->input('call_duration_minutes');

        if ($durationInput === null) {
            $durationInput = $office->callTimeoutMinutes();
        }

        $duration = QueueTicket::normalizeCallDuration($durationInput);

        if ($duration === null) {
            return $this->fail('Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.', 422);
        }

        $named = null;

        if ($request->filled('ticket_id')) {
            $named = QueueTicket::find($request->integer('ticket_id'));

            $this->guardTicketInOffice($office, $named);

            if ($named->status !== QueueTicket::STATUS_WAITING) {
                return $this->fail("Ticket {$named->queue_number} is not waiting any more.", 422);
            }
        }

        $ticket = (new QueueCounter())->callNext($office, $user, $named, $duration);

        if (! $ticket) {
            $serving = QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->exists();

            return $this->fail(
                $serving
                    ? "{$office->name} already has a ticket being served."
                    : "No waiting tickets for {$office->name}.",
                422
            );
        }

        $relations = ['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff'];

        return $this->json([
            'message' => ($ticket->isPwd() ? 'PWD priority — ' : '')."Now serving {$ticket->queue_number}.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()->loadMissing($relations)),
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/tickets/{ticket}/serve
     */
    public function serve(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $this->guardTicketInOffice($office, $ticket);

        if ($ticket->status !== QueueTicket::STATUS_SERVING) {
            return $this->fail("Ticket {$ticket->queue_number} is not being served.", 422);
        }

        try {
            (new QueueCounter())->serve($ticket, $user, $office);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? "Ticket {$ticket->queue_number} cannot be marked as served.",
                422
            );
        }

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} marked as served.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            // Re-sent so the operator's screen updates from the response they
            // already handled, instead of waiting out a poll interval to see
            // whether auto-call fired.
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/tickets/{ticket}/arrive
     *
     * Stop the countdown: stamps arrived_at and clears the deadline while
     * keeping status=serving so the ticket stays workable. Idempotent and
     * never expirable afterwards. Refused when already expired.
     */
    public function arrive(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $this->guardTicketInOffice($office, $ticket);

        try {
            $ticket = (new QueueCounter())->confirmArrival($ticket);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? "Ticket {$ticket->queue_number} cannot be marked as arrived.",
                422
            );
        }

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} arrived — timer stopped.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/tickets/{ticket}/cancel
     *
     * Staff cancel of a serving/waiting ticket with an optional reason.
     * Terminal, idempotent-guarded, logged via TicketCancellationNotifier
     * with staff member, office, ticket number, reason and timestamp.
     */
    public function cancelCall(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $this->guardTicketInOffice($office, $ticket);

        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            (new QueueCounter())->cancelCall($ticket, $data['cancel_reason'] ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? "Ticket {$ticket->queue_number} cannot be cancelled.",
                422
            );
        }

        TicketCancellationNotifier::notify($ticket->fresh(), $user, $data['cancel_reason'] ?? null);

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} cancelled. You may call the next student.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * GET /api/v1/queues/{office}/timeout
     *
     * This office's saved Queue Timeout Duration (default 5).
     */
    public function getTimeout(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        return $this->json([
            'office_id' => $office->id,
            'call_timeout_minutes' => $office->callTimeoutMinutes(),
            'default_call_timeout_minutes' => QueueTicket::DEFAULT_CALL_DURATION_MINUTES,
            'min_call_timeout_minutes' => QueueTicket::MIN_CALL_DURATION_MINUTES,
            'max_call_timeout_minutes' => QueueTicket::MAX_CALL_DURATION_MINUTES,
        ]);
    }

    /**
     * PUT /api/v1/queues/{office}/timeout
     *
     * Save this office's Queue Timeout Duration (1-60, default 5).
     */
    public function setTimeout(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $data = $request->validate([
            'call_duration_minutes' => ['required', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        try {
            $minutes = $office->setCallTimeoutMinutes($data['call_duration_minutes']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? 'Invalid call duration.',
                422
            );
        }

        return $this->json([
            'message' => "Queue timeout for {$office->name} set to {$minutes} minute(s).",
            'office_id' => $office->id,
            'call_timeout_minutes' => $minutes,
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/tickets/{ticket}/recall
     *
     * Call Again: re-arm the same serving ticket with a fresh server-stamped
     * deadline. Keeps the serving position; never duplicates or requeues.
     * Body may carry call_duration_minutes (1-60); omitted means this
     * office's saved timeout. Refused when not serving or already arrived.
     * Serialized on the row lock so concurrent recalls cannot double-arm.
     */
    public function recall(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $this->guardTicketInOffice($office, $ticket);

        $data = $request->validate([
            'call_duration_minutes' => ['nullable', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        $durationInput = $data['call_duration_minutes'] ?? $ticket->call_duration_minutes ?? $office->callTimeoutMinutes();
        $duration = QueueTicket::normalizeCallDuration($durationInput);

        if ($duration === null) {
            return $this->fail('Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.', 422);
        }

        try {
            $ticket = (new QueueCounter())->recall($ticket, $user, $office, $duration);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? "Ticket {$ticket->queue_number} cannot be called again.",
                422
            );
        }

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} called again — new {$duration} minute(s) deadline.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/expire
     *
     * Count this office's overdue calls without moving them. The scheduler
     * runs the same non-destructive count every minute; this is the manual /
     * polling fallback so a staff console can raise the Call Again / No-Show
     * popup even when cron has not run. Resolution stays a staff decision.
     */
    public function expire(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $overdue = (new QueueCounter())->expireOverdue($office);

        return $this->json([
            'message' => $overdue > 0
                ? "{$overdue} overdue ticket(s) awaiting Call Again or No-Show."
                : 'No overdue tickets.',
            'released' => $overdue,
            'overdue' => $overdue,
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * POST /api/v1/queues/{office}/tickets/{ticket}/no-show
     *
     * Terminal No-Show: the serving ticket leaves the live queue permanently,
     * frees the window, and stays in history/reports with its original call
     * timestamps plus no_show_at / no_show_by. Only meaningful while serving;
     * refused after arrival. Duplicate POSTs serialize on the row lock.
     */
    public function noShow(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $this->guardTicketInOffice($office, $ticket);

        if (! in_array($ticket->status, [QueueTicket::STATUS_SERVING, QueueTicket::STATUS_NO_SHOW], true)) {
            return $this->fail(
                "Ticket {$ticket->queue_number} is {$ticket->status}, so it cannot be marked as a no-show.",
                422
            );
        }

        try {
            $ticket = (new QueueCounter())->markNoShow($ticket, $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? "Ticket {$ticket->queue_number} cannot be marked as a no-show.",
                422
            );
        }

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} marked as No-Show.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            'board' => $this->boardFor($office),
        ]);
    }

    /**
     * PUT /api/v1/queues/{office}/tickets/{ticket}
     *
     * The office-scoped spelling of updateStatus(), kept because the queue
     * routes are the natural place to look for it. It confirms the office
     * matches, then hands over.
     */
    public function updateTicket(Request $request, Office $office, QueueTicket $ticket): JsonResponse
    {
        $office = $this->operationalOffice($office->id);
        $this->guardTicketInOffice($office, $ticket);

        return $this->updateStatus($request, $ticket);
    }

    /**
     * PUT /api/queues/{ticket}
     *
     * The flat spelling: the office comes from the ticket itself. Still admin
     * only, and still refuses the two transitions that have their own guarded
     * actions.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateStatus(Request $request, QueueTicket $ticket): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', QueueTicket::STATUSES)],
        ]);

        // `serving` is refused because setting a ticket to serving by hand is
        // exactly what callNext() exists to do safely: it checks that nothing
        // is already at the window and stamps called_at. `served` is refused
        // for the same reason - serve() is what also completes the batch and
        // honours auto-call.
        if ($data['status'] === QueueTicket::STATUS_SERVING) {
            return $this->fail('Use "Call next" to move a ticket to serving.', 422);
        }

        if ($data['status'] === QueueTicket::STATUS_SERVED) {
            return $this->fail('Use "Mark served" to finish a ticket that was being served.', 422);
        }

        if ($ticket->status === $data['status']) {
            return $this->fail("Ticket {$ticket->queue_number} is already {$ticket->status}.", 422);
        }

        $updates = [
            'status' => $data['status'],
            'arrived_at' => null,
        ];

        if ($data['status'] === QueueTicket::STATUS_NO_SHOW) {
            // Admin-set No-Show keeps the call audit and stamps the action.
            $updates['no_show_at'] = $ticket->no_show_at ?? now();
            $updates['no_show_by'] = $ticket->no_show_by ?? $this->account($request)->id;
            $updates['cancelled_at'] = null;
            $updates['cancel_reason'] = null;
        } else {
            $updates['called_at'] = null;
            $updates['expires_at'] = null;
            $updates['cancelled_at'] = $data['status'] === QueueTicket::STATUS_CANCELLED ? now() : null;
            $updates['cancel_reason'] = null;
            if ($data['status'] !== QueueTicket::STATUS_NO_SHOW) {
                $updates['no_show_at'] = null;
                $updates['no_show_by'] = null;
            }
        }

        $ticket->update($updates);

        (new QueueDeploymentManager())->refreshAfterService($ticket);

        if ($data['status'] === QueueTicket::STATUS_CANCELLED) {
            TicketCancellationNotifier::notify($ticket->fresh(), $this->account($request));
        }

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} is now {$ticket->status}.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
            'board' => $this->boardFor(Office::findOrFail($ticket->office_id)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch lifecycle
    |--------------------------------------------------------------------------
    |
    | Four transitions, mirroring the four controls on the admin Offices screen
    | and the staff equivalents on the counter console. They are admin and
    | assigned-staff only, and they run the same QueueDeploymentManager the
    | Blade portal calls.
    |
    */

    public function deployBatch(Request $request, Office $office): JsonResponse
    {
        return $this->transitionBatch($request, $office, 'deploy');
    }

    public function closeBatch(Request $request, Office $office): JsonResponse
    {
        return $this->transitionBatch($request, $office, 'close');
    }

    public function completeBatch(Request $request, Office $office): JsonResponse
    {
        return $this->transitionBatch($request, $office, 'completeAndRestart');
    }

    public function startBatch(Request $request, Office $office): JsonResponse
    {
        return $this->transitionBatch($request, $office, 'start');
    }

    /*
    |--------------------------------------------------------------------------
    | Office online/offline switch
    |--------------------------------------------------------------------------
    |
    | Staff and admins flip the daily "are you accepting students right now?"
    | flag the same way they deploy a batch: office-scoped, so a token can only
    | change the assignment it has been given. Every office — including each
    | per-course Registrar counter — carries its own switch.
    |
    */

    public function setStatus(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', [Office::STATUS_ONLINE, Office::STATUS_OFFLINE])],
        ]);

        $updated = $office->applyStatusToGroup($data['status']);

        return $this->json([
            'message' => $updated > 0
                ? Office::statusMessage($office, $data['status'])
                : 'The office status is unchanged.',
            'status' => $data['status'],
            'offices' => $updated,
            'office' => ApiPresenter::office($office->fresh()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The full working set for one office.
     *
     * Shared by show() and index() so the office-scoped and flat spellings
     * cannot drift, and used by every mutating action that returns `board`.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(Office $office): array
    {
        $relations = ['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff'];

        $nowServing = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->latest('called_at')
            ->first();

        // PWD ahead of regular, then oldest first - the same order QueueCounter
        // calls numbers in, so the first row here is always the number the
        // Call Next button will promote.
        $waiting = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->whereDate('created_at', today())
            ->orderByRaw("CASE WHEN priority = 'pwd' THEN 0 ELSE 1 END, created_at ASC")
            ->limit(200)
            ->get();

        $recentlyServed = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVED)
            ->whereDate('created_at', today())
            ->latest('served_at')
            ->limit(15)
            ->get();

        return [
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ],
            // The full ticket, not a reduced reference: the counter screen
            // shows whose number it is and what they are there for.
            'now_serving' => $nowServing
                ? ApiPresenter::ticket($nowServing->loadMissing($relations))
                : null,
            'waiting' => ApiPresenter::tickets($waiting->loadMissing($relations)),
            'recently_served' => ApiPresenter::tickets($recentlyServed->loadMissing($relations)),
            'batch' => ApiPresenter::deployment(QueueDeployment::activeFor($office)),
            'window_open' => QueueDeployment::officeWindowIsOpen($office),
            'waiting_count' => $waiting->count(),
            'serving_count' => $nowServing ? 1 : 0,
            'served_count' => QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVED)
                ->whereDate('created_at', today())
                ->count(),
        ];
    }

    /**
     * Run one batch transition and describe the result.
     *
     * A ValidationException from the manager (for example `start` on a batch
     * that is still running) is turned into a 422 carrying the manager's own
     * message, because that text is written to be shown to an operator and
     * is more useful than anything a generic handler would produce.
     */
    private function transitionBatch(Request $request, Office $office, string $action): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        $office = $this->guardOffice($user, $this->operationalOffice($office->id));

        $manager = new QueueDeploymentManager();

        $slots = $request->filled('max_slots')
            ? (int) $request->validate(['max_slots' => ['integer', 'min:1', 'max:500']])['max_slots']
            : null;

        try {
            $deployment = match ($action) {
                'deploy' => $manager->deploy($office, $slots),
                'close' => $manager->close($office),
                'completeAndRestart' => $manager->completeAndRestart($office),
                'start' => $manager->start($office, $slots),
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first() ?? 'That batch action is not available right now.',
                422
            );
        }

        return $this->json([
            'message' => match ($action) {
                'deploy' => $deployment->isOpen()
                    ? "{$office->name} batch #{$deployment->batch_no} is now open with {$deployment->max_slots} slots for students."
                    : "{$office->name} is now serving batch #{$deployment->batch_no}.",
                'close' => "Batch #{$deployment->batch_no} sent to history; a fresh batch is open.",
                'completeAndRestart' => 'Batch completed and a new one opened.',
                default => "Batch #{$deployment->batch_no} opened.",
            },
            'batch' => ApiPresenter::deployment($deployment),
        ]);
    }

    /**
     * The live board for an office, for a client to apply straight after a
     * counter action.
     */
    private function boardFor(Office $office): array
    {
        $nowServing = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->latest('called_at')
            ->first();

        return ApiPresenter::board(
            $office,
            $nowServing,
            QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVED)
                ->whereDate('created_at', today())
                ->count(),
            QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVED)
                ->whereDate('created_at', today())
                ->latest('served_at')
                ->limit(10)
                ->get()
        );
    }

    /**
     * Refuse a ticket that belongs to a different office.
     *
     * 403 rather than 404: the caller has already proved it works here, and
     * telling it which office the ticket is really at is what lets the client
     * show a useful message instead of "not found".
     */
    private function guardTicketInOffice(Office $office, QueueTicket $ticket): void
    {
        if ($ticket->office_id !== $office->id) {
            $this->refuse('That ticket is not assigned to this office.', 403);
        }
    }
}