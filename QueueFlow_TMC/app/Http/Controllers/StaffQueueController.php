<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Services\QueueCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StaffQueueController extends Controller
{
    use StaffOfficeScope;

    public function callNext(Request $request)
    {
        $office = $this->officeOf($request);

        $validated = $request->validate([
            'ticket_id' => ['nullable', 'integer', 'exists:queue_tickets,id'],
            'call_duration_minutes' => ['nullable', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        // No explicit duration: use this office's saved Queue Timeout
        // Duration so the setting survives refreshes and reconnects.
        $durationInput = array_key_exists('call_duration_minutes', $validated) && $validated['call_duration_minutes'] !== null
            ? $validated['call_duration_minutes']
            : $office->callTimeoutMinutes();

        $duration = QueueTicket::normalizeCallDuration($durationInput);

        if ($duration === null) {
            return back()->withErrors([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ])->withInput();
        }

        $named = null;

        if ($request->filled('ticket_id')) {
            $named = QueueTicket::find($request->integer('ticket_id'));

            // Same office rule as every other counter action: a staff member
            // can only ever call a ticket at the office they are assigned to.
            abort_if($named->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

            if ($named->status !== QueueTicket::STATUS_WAITING) {
                return back()->with(
                    'status',
                    "Ticket {$named->queue_number} is not waiting any more."
                );
            }

            // The per-row button is only rendered for verified PWD tickets,
            // but the endpoint re-checks anyway so a crafted POST cannot call
            // a regular ticket through the priority path - or a PWD ticket
            // whose proof file is gone.
            if (! $named->hasVerifiedEvidence()) {
                return back()->with(
                    'status',
                    "Ticket {$named->queue_number} has no verified PWD evidence."
                );
            }
        }

        try {
            $ticket = (new QueueCounter())->callNext($office, $request->user(), $named, $duration);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        if (! $ticket) {
            $serving = QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->exists();

            return back()->with(
                'status',
                $serving
                    ? "{$office->name} already has a ticket being served."
                    : "No waiting tickets for {$office->name}."
            );
        }

        return back()->with(
            'status',
            ($ticket->isPwd() ? 'PWD priority — ' : '') . "Now serving {$ticket->queue_number}."
        );
    }

    /**
     * Save this office's Queue Timeout Duration (minutes).
     *
     * Validated 1-60, default 5. Applies to every future Call Next at this
     * office until changed again; the already-serving countdown keeps its
     * own server-stamped deadline.
     */
    public function updateTimeout(Request $request)
    {
        $office = $this->officeOf($request);

        $validated = $request->validate([
            'call_duration_minutes' => ['required', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        try {
            $minutes = $office->setCallTimeoutMinutes($validated['call_duration_minutes']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with(
            'status',
            "Queue timeout for {$office->name} set to {$minutes} minute(s)."
        );
    }

    /**
     * Confirm the student arrived and stop the countdown.
     *
     * Records arrived_at and clears the deadline; status stays serving so
     * the ticket remains workable for Mark Served. Never resumes on
     * refresh because the deadline row is cleared, not paused. Idempotent.
     */
    public function confirmArrival(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        try {
            $ticket = (new QueueCounter())->confirmArrival($ticket);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?? "Ticket {$ticket->queue_number} cannot be marked as arrived.";

            return back()->with('status', $message);
        }

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} arrived — timer stopped. The student is being attended to."
        );
    }

    /**
     * Atomically promote the oldest waiting ticket to serving (PWD first).
     *
     * The action itself lives in App\Services\QueueCounter, shared with the
     * bearer-token API the Flutter client calls, so the window and the phone
     * can never disagree about who is up next. This controller kept its own
     * copy of that logic for most of the API's life; leaving it in place is
     * how two slightly different orderings would end up deciding whose number
     * gets called.
     */
    public function markServed(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        if ($ticket->status !== QueueTicket::STATUS_SERVING) {
            return back()->with(
                'status',
                "Ticket {$ticket->queue_number} is not being served."
            );
        }

        try {
            (new QueueCounter())->serve($ticket, $request->user(), $office);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?? "Ticket {$ticket->queue_number} cannot be marked as served.";

            return back()->with('status', $message);
        }

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} marked as served."
        );
    }

    /**
     * Cancel the ticket currently at the window.
     *
     * Terminal: clears the deadline, records the reason, timestamp and staff
     * member through TicketCancellationNotifier (via QueueCounter::cancelCall's
     * batch refresh the caller is freed), and never counts as a completed
     * service. A reason is required: the student sees it on their ticket, so
     * a cancellation without one is refused. Confirmation happens in the
     * Blade form (onsubmit confirm) so a double-click cannot void a number
     * by accident. Duplicate cancels are refused rather than stacked.
     */
    public function cancelCall(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        if (! in_array($ticket->status, [QueueTicket::STATUS_SERVING, QueueTicket::STATUS_WAITING], true)) {
            return back()->with(
                'status',
                "Ticket {$ticket->queue_number} is already {$ticket->status}."
            );
        }

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:255'],
        ]);

        $reason = trim($validated['cancel_reason']);

        if ($reason === '') {
            return back()->withErrors(['cancel_reason' => 'A cancellation reason is required.'])->withInput();
        }

        try {
            (new QueueCounter())->cancelCall($ticket, $reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?? "Ticket {$ticket->queue_number} cannot be cancelled.";

            return back()->with('status', $message);
        }

        \App\Services\TicketCancellationNotifier::notify($ticket->fresh(), $request->user(), $reason);

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} cancelled. You may call the next student."
        );
    }

    /**
     * Call the same serving ticket again: new server-stamped deadline.
     *
     * Keeps the ticket in the serving position; never duplicates or requeues.
     * Uses the staff-supplied duration when given, else this office's saved
     * Queue Timeout Duration. Guarded to this office, refused when not
     * serving or already arrived, and serialized on the row lock so double
     * clicks cannot double-recall.
     */
    public function recall(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        $validated = $request->validate([
            'call_duration_minutes' => ['nullable', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        $durationInput = $validated['call_duration_minutes'] ?? $ticket->call_duration_minutes ?? $office->callTimeoutMinutes();
        $duration = QueueTicket::normalizeCallDuration($durationInput);

        if ($duration === null) {
            return back()->withErrors([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ])->withInput();
        }

        try {
            $ticket = (new QueueCounter())->recall($ticket, $request->user(), $office, $duration);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?? "Ticket {$ticket->queue_number} cannot be called again.";

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => $message, 'errors' => $e->errors()], 422);
            }

            return back()->with('status', $message);
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            $ticket->loadMissing(['student', 'course']);
            return response()->json([
                'message' => "Ticket {$ticket->queue_number} called again — new {$duration} minute(s) deadline.",
                'ticket' => [
                    'id' => $ticket->id,
                    'queue_number' => $ticket->queue_number,
                    'called_at' => optional($ticket->called_at)->toIso8601String(),
                    'expires_at' => optional($ticket->expires_at)->toIso8601String(),
                    'call_duration_minutes' => $ticket->effectiveCallDuration(),
                    'remaining_seconds' => $ticket->remainingSeconds(),
                    'recall_count' => (int) ($ticket->recall_count ?? 0),
                ],
            ]);
        }

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} called again — new {$duration} minute(s) deadline."
        );
    }

    /**
     * Live JSON state for the staff console polling loop.
     *
     * Returns the serving ticket with its server-stamped deadline
     * (called_at / expires_at / remaining_seconds / is_expired / is_arrived /
     * recall_count), the waiting list rows, the active batch, the waiting /
     * no-show / served counts and the office timeout — all from the database
     * and scoped to the staff member's own office, so a refresh, a second
     * device, or a reconnect reads the same queue. No mutation: expiry stays
     * serving+expired until staff act.
     */
    public function state(Request $request)
    {
        $office = $this->officeOf($request);

        $nowServing = QueueTicket::with(['student', 'course'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->latest('called_at')
            ->first();

        $waitingCount = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->whereDate('created_at', today())
            ->count();

        $noShowCount = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_NO_SHOW)
            ->whereDate('created_at', today())
            ->count();

        $servedToday = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVED)
            ->whereDate('created_at', today())
            ->count();

        // Today's waiting list in call order (PWD first), capped so one
        // poll never carries the whole campus history. The console
        // re-renders its waiting rows from exactly these rows.
        $waitingTickets = QueueTicket::with(['student', 'course'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->whereDate('created_at', today())
            ->orderByRaw("CASE WHEN priority = 'pwd' THEN 0 ELSE 1 END, created_at ASC")
            ->limit(100)
            ->get()
            ->map(fn (QueueTicket $ticket) => [
                'id' => $ticket->id,
                'queue_number' => $ticket->queue_number,
                'student_name' => $ticket->student->name ?? 'Walk-in',
                'course_code' => $ticket->course->code ?? null,
                'priority' => $ticket->priority,
                'purpose' => $ticket->purpose,
                'has_evidence' => $ticket->hasVerifiedEvidence(),
                'has_evidence_path' => filled($ticket->priority_evidence),
                'evidence_url' => route('staff.queues.evidence', $ticket),
                'created_at' => optional($ticket->created_at)->toIso8601String(),
            ])
            ->values();

        $deployment = QueueDeployment::activeFor($office);

        $cancelUnread = StaffNotification::forOffice($office->id)
            ->cancelled()
            ->unread()
            ->count();

        $latestTicketId = (int) QueueTicket::where('office_id', $office->id)
            ->whereDate('created_at', today())
            ->max('id');

        $payload = null;

        if ($nowServing) {
            $payload = [
                'id' => $nowServing->id,
                'queue_number' => $nowServing->queue_number,
                'student_name' => $nowServing->student->name ?? 'Walk-in',
                'student_id_no' => $nowServing->student->student_id ?? null,
                'office' => $office->name,
                'office_code' => $office->code,
                'status' => $nowServing->status,
                'called_at' => optional($nowServing->called_at)->toIso8601String(),
                'call_duration_minutes' => $nowServing->effectiveCallDuration(),
                'expires_at' => optional($nowServing->expires_at)->toIso8601String(),
                'remaining_seconds' => $nowServing->remainingSeconds(),
                'is_expired' => $nowServing->isExpired(),
                'is_arrived' => $nowServing->isArrived(),
                'arrived_at' => optional($nowServing->arrived_at)->toIso8601String(),
                'recall_count' => (int) ($nowServing->recall_count ?? 0),
            ];
        }

        return response()->json([
            'office_id' => $office->id,
            'office' => $office->name,
            'office_code' => $office->code,
            'call_timeout_minutes' => $office->callTimeoutMinutes(),
            'server_time' => now()->toIso8601String(),
            'now_serving' => $payload,
            'waiting_count' => $waitingCount,
            'waiting_tickets' => $waitingTickets,
            'no_show_count' => $noShowCount,
            'served_today' => $servedToday,
            'cancel_unread' => $cancelUnread,
            'latest_ticket_id' => $latestTicketId,
            'deployment' => $deployment ? [
                'id' => $deployment->id,
                'batch_no' => (int) $deployment->batch_no,
                'status' => $deployment->status,
                'max_slots' => (int) $deployment->max_slots,
                'submitted' => $deployment->submittedCount(),
                'served' => $deployment->servedCount(),
            ] : null,
        ]);
    }

    /**
     * Mark the serving ticket as a terminal No-Show.
     *
     * Frees the window for the next eligible student and preserves the row
     * in history/reports with called_at, expires_at, no_show_at and the
     * acting staff member. Refused after arrival; duplicate POSTs serialize
     * on the row lock and the second is refused as already no-show.
     */
    public function markNoShow(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        if ($ticket->status !== QueueTicket::STATUS_SERVING && $ticket->status !== QueueTicket::STATUS_NO_SHOW) {
            return back()->with(
                'status',
                "Ticket {$ticket->queue_number} is not being served, so it cannot be marked as a no-show."
            );
        }

        try {
            (new QueueCounter())->markNoShow($ticket, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?? "Ticket {$ticket->queue_number} cannot be marked as a no-show.";

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => $message, 'errors' => $e->errors()], 422);
            }

            return back()->with('status', $message);
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'message' => "Ticket {$ticket->queue_number} marked as No-Show. You may call the next student.",
                'ticket_id' => $ticket->id,
            ]);
        }

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} marked as No-Show. You may call the next student."
        );
    }

    public function evidence(Request $request, QueueTicket $ticket)
    {
        $office = $this->officeOf($request);

        abort_if($ticket->office_id !== $office->id, 403, 'Ticket is not assigned to your office.');

        abort_unless($ticket->isPwd(), 404);

        $path = $ticket->priority_evidence;

        if (! filled($path) || ! Storage::disk('local')->exists($path)) {
            abort(404, 'PWD evidence not found.');
        }

        return Storage::disk('local')->response($path);
    }
}
