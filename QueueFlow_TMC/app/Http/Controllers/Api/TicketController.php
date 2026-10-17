<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use App\Services\TicketCancellationNotifier;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Ticket creation, listing, cancellation and PWD evidence.
 *
 * Authorization is two-layered and both layers matter:
 *
 *   1. Ownership - a student may only ever see or touch their own tickets.
 *      A student who guesses an id sees 404, not 403: telling them the row
 *      exists would let them enumerate the whole campus's queue numbers.
 *
 *   2. Office scope - staff may only act on tickets at the one office they
 *      are assigned to (guardOffice), which is the same rule the Blade
 *      counter console enforces.
 *
 * Creation itself is NOT reimplemented here. Everything that decides whether a
 * ticket may be issued - office active, inside the window, service and course
 * valid, batch has a free slot, PWD needs evidence - already lives in
 * QueueTicketCreator and is shared with the admin and student portals.
 *
 * The one rule that is NOT in the creator is `max_active_tickets_per_student`,
 * because it applies to a student taking their own ticket rather than to the
 * queue itself. See guardActiveTicketLimit().
 */
class TicketController extends ApiController
{
    /**
     * GET /api/v1/tickets
     *
     * The student's own queue; the assigned office's live list for staff; the
     * campus-wide list for admin. One endpoint, because the three differ only
     * in the scope they apply before the same query runs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->account($request);

        $tickets = match ($user->role) {
            User::ROLE_ADMIN => QueueTicket::with(['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff'])
                ->when($request->filled('office_id'), fn ($q) => $q->where('office_id', $request->integer('office_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->latest('id')
                ->limit(200)
                ->get(),

            User::ROLE_STAFF => $this->officeTickets(
                $request,
                $this->guardOffice($user, $this->operationalOffice(
                    $request->integer('office_id') ?? optional($this->staffOffice($user))->id
                ))
            ),

            default => QueueTicket::where('user_id', $user->id)
                ->with(['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff'])
                ->latest('id')
                ->limit(100)
                ->get(),
        };

        $payload = ApiPresenter::tickets($tickets);

        // A waiting ticket carries its own position in line. That is the one
        // number a student opens the screen for, so it is attached to the list
        // rather than fetched per row afterwards.
        foreach ($payload as $index => $ticket) {
            if ($ticket['status'] === QueueTicket::STATUS_WAITING) {
                $payload[$index]['progress'] = ApiPresenter::progress($tickets[$index]);
            }
        }

        return $this->json([
            'tickets' => $payload,
            'counts' => $user->isStudent()
                ? ApiPresenter::counts($user)
                : $this->scopedCounts($user, $request),
        ]);
    }

    /**
     * POST /api/v1/tickets
     *
     * Multipart, because a PWD ticket must carry an evidence photo. The
     * content type is therefore whatever the client's FormData produced, which
     * is why the client sends that rather than application/json.
     *
     * Students take their own ticket and name nobody. Staff and admins may
     * take one on someone's behalf - a walk-in without an account, or a
     * number handed out at the counter - and must name who it is for, because
     * there is no other way to attribute the queue entry.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, QueueTicketCreator $creator): JsonResponse
    {
        $user = $this->account($request);

        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'service_id' => ['required', 'integer', 'exists:office_services,id'],
            'purpose' => ['required', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'in:'.implode(',', QueueTicket::PRIORITIES)],
            'priority_evidence' => ['nullable', 'image', 'max:'.QueueTicketCreator::MAX_EVIDENCE_SIZE],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $office = $this->operationalOffice($data['office_id']);

        // A staff token may only issue tickets at its own office, exactly as
        // for every other queue action.
        if ($user->isStaff()) {
            $this->guardOffice($user, $office);
        }

        // Refused here so the phone gets the exact JSON shape the Dart client
        // decodes for "the office is offline" without a form-error envelope.
        // QueueTicketCreator::create() is still the authoritative gate for
        // every other entry point; this just answers the API's own client
        // before the creator would.
        if (! $office->isOnline()) {
            return $this->fail(QueueTicketCreator::OFFLINE_MESSAGE, 422, [
                'success' => false,
            ]);
        }

        [$owner, $ownerCourse, $yearLevel] = $this->resolveTicketOwner($user, $data);

        $selfService = $user->isStudent() && $owner->id === $user->id;

        // Registrar tickets are queue-assigned from the account, not from the
        // request: a student registered under BSIT belongs in the BSIT queue
        // even if the client posts another course's ids. The client value is
        // replaced below rather than trusted.
        $courseId = $data['course_id'] ?? null;

        if ($owner->isStudent() && str_starts_with(strtoupper((string) $office->code), 'REG-')) {
            $courseId = $this->resolveRegistrarCourse($owner, $office);
        }

        try {
            $this->guardActiveTicketLimit($user, $owner);

            $result = $creator->create([
                'office_id' => $office->id,
                'service_id' => $data['service_id'],
                'course_id' => $courseId,
                'year_level' => $yearLevel,
                'priority' => $data['priority'] ?? QueueTicket::PRIORITY_REGULAR,
                'priority_evidence' => $request->file('priority_evidence'),
                'user_id' => $owner->id,
                'purpose' => $data['purpose'],
                // The creator validates the service/station against this, so it
                // comes from the account the ticket belongs to rather than from
                // the request body - otherwise a student could post someone
                // else's course and be served at a window their program is not
                // assigned to.
                'student_course' => $ownerCourse,
                // Atomic backstop for the limit above: the pre-check plus a
                // separate write is a race two simultaneous requests both win.
                'enforce_student_limit' => $selfService,
            ]);
        } catch (ValidationException $e) {
            $refusal = $this->activeTicketRefusal($user, $owner, $e);

            if ($refusal !== null) {
                return $refusal;
            }

            throw $e;
        }

        $ticket = $result['ticket'];
        $ticket->loadMissing(['office', 'service', 'station', 'course', 'deployment']);

        return $this->json([
            'message' => $result['message'],
            'ticket' => ApiPresenter::ticket($ticket),
            'progress' => ApiPresenter::progress($ticket),
        ], 201);
    }

    /**
     * Work out whose ticket this is, and whose course the service check uses.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: ?string, 2: ?string} owner, course, year level
     */
    private function resolveTicketOwner(User $actor, array $data): array
    {
        if ($actor->isStudent()) {
            return [$actor, $actor->course, $data['year_level'] ?? $actor->year_level];
        }

        $owner = User::whereKey($data['user_id'] ?? null)->first();

        if (! $owner) {
            throw ValidationException::withMessages([
                'user_id' => 'Name the account this ticket is for.',
            ]);
        }

        // The service/course check follows the ticket's owner, not the operator
        // at the counter: a Registrar office serves its own students, and
        // staff are frequently assigned to a different college than the
        // person in front of them.
        return [$owner, $owner->course, $data['year_level'] ?? $owner->year_level];
    }

    /**
     * Resolve the Registrar course queue from the account, not the request.
     *
     * The Flutter client auto-assigns the student's registered course, but a
     * hand-built request could still post another course's office or course
     * id. The office must be the queue for the owner's registered course and
     * the course id comes from the server's own lookup, so neither client
     * value can move a student into another course's queue. A missing or
     * unknown course is a 422 naming the administrator rather than a ticket
     * in the wrong line; a missing course *row* falls back to null (the
     * office already places the ticket) rather than refusing a valid queue.
     */
    private function resolveRegistrarCourse(User $owner, Office $office): ?int
    {
        $registered = strtoupper(trim((string) $owner->course));

        if ($registered === '' || ! in_array($registered, User::COURSES, true)) {
            throw ValidationException::withMessages([
                'course_id' => 'Your profile has no course set, so the Registrar cannot place you in a queue. Please contact the administrator to update your account.',
            ]);
        }

        $expectedCode = 'REG-'.$registered;

        if (strtoupper((string) $office->code) !== $expectedCode) {
            throw ValidationException::withMessages([
                'office_id' => "Your account is registered under {$registered}, so you can only take a ticket in the {$registered} Registrar queue.",
            ]);
        }

        $course = Course::where('code', $registered)->where('is_active', true)->first();

        return $course ? (int) $course->id : null;
    }

    /**
     * Refuse a second live ticket while the student is already in a queue.
     *
     * WHY THIS LIVES HERE AND IS NOT ALREADY IN QueueTicketCreator
     *
     * The `max_active_tickets_per_student` setting has only ever been enforced
     * on the Blade route, in StudentQueueController::store(). The API route is
     * the one the Flutter client uses, so before this the same student could
     * hold a waiting ticket from the web portal and then take an unlimited
     * number of further ones from their phone - two front doors answering the
     * same question differently, and the phone being the one with no rule.
     *
     * The count, the setting and the meaning of "active" are copied from
     * StudentQueueController rather than invented here, so both doors refuse
     * with the same wording for the same reason. QueueTicketCreator is
     * deliberately not the home for this: it is shared with the staff and admin
     * console, where issuing a replacement number on someone's behalf is a
     * legitimate thing to do, and the limit is a rule about a student taking
     * their own ticket.
     *
     * Deliberately checked BEFORE the creator runs, so a refused request never
     * consumes a slot in the day's batch.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guardActiveTicketLimit(User $actor, User $owner): void
    {
        // Only a student taking their own ticket is capped. Staff and admin
        // issuing a ticket at the counter are not, and neither is an operator
        // correcting a mis-issued number for somebody else.
        if (! $actor->isStudent() || $owner->id !== $actor->id) {
            return;
        }

        $maxActive = (int) Setting::get('max_active_tickets_per_student', 1);

        // A limit of 0 or less is read as "no limit configured" rather than as
        // "refuse everything", so an admin who blanks the field has not
        // silently locked every student out of the queue.
        if ($maxActive < 1) {
            return;
        }

        $activeCount = QueueTicket::where('user_id', $owner->id)
            ->whereIn('status', [
                QueueTicket::STATUS_WAITING,
                QueueTicket::STATUS_SERVING,
            ])
            ->whereDate('created_at', today())
            ->count();

        if ($activeCount >= $maxActive) {
            throw ValidationException::withMessages([
                'office_id' => $maxActive === 1
                    ? 'You already have an active queue ticket. Cancel it before taking another.'
                    : "You already have the maximum of {$maxActive} active tickets today.",
            ]);
        }
    }

    /**
     * Enrich a per-student limit refusal with the ticket blocking them.
     *
     * Returns null unless this is a student refused for their own active
     * ticket, so every other validation error keeps its existing shape. The
     * `errors` bag is preserved byte-for-byte (the Flutter client reads the
     * sentence from it); `active_ticket` is additive detail for clients that
     * want to show the blocking number without a second request.
     */
    private function activeTicketRefusal(User $user, User $owner, ValidationException $e): ?JsonResponse
    {
        if (! $user->isStudent() || $owner->id !== $user->id) {
            return null;
        }

        $messages = $e->errors()['office_id'] ?? [];

        if (! is_array($messages) || ! str_contains(implode(' ', $messages), 'active')) {
            return null;
        }

        $active = QueueTicket::where('user_id', $owner->id)
            ->whereIn('status', [
                QueueTicket::STATUS_WAITING,
                QueueTicket::STATUS_SERVING,
            ])
            ->whereDate('created_at', today())
            ->with(['office', 'service', 'station', 'course', 'deployment'])
            ->latest('id')
            ->first();

        if (! $active) {
            return null;
        }

        return $this->fail($messages[0], 422, [
            'success' => false,
            'errors' => $e->errors(),
            'active_ticket' => ApiPresenter::ticket($active),
        ]);
    }

    /**
     * GET /api/v1/tickets/{ticket}
     *
     * Authoritative read: an expired serving ticket stays serving+expired so
     * the phone shows "time expired" until staff choose Call Again or
     * No-Show. Never auto-releases; arrival and terminal states are decided
     * by staff actions, not by this read.
     */
    public function show(Request $request, QueueTicket $ticket): JsonResponse
    {
        $this->guardTicketAccess($this->account($request), $ticket);

        $ticket->refresh();
        $ticket->loadMissing(['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff']);

        $payload = ApiPresenter::ticket($ticket);

        // Progress only means anything while the ticket is still in line.
        if ($ticket->status === QueueTicket::STATUS_WAITING) {
            $payload['progress'] = ApiPresenter::progress($ticket);
        }

        return $this->json(['ticket' => $payload]);
    }

    /**
     * POST /api/v1/tickets/{ticket}/cancel
     *
     * A student may withdraw their own ticket only while it is still waiting -
     * once a number has been called the student physically walks away from the
     * window, so the counter has to be told, not the student. Staff and admin
     * may cancel on someone's behalf, which is how a no-show or a mis-issued
     * ticket gets cleared.
     */
    public function cancel(Request $request, QueueTicket $ticket): JsonResponse
    {
        $user = $this->account($request);

        $this->guardTicketAccess($user, $ticket);

        if ($user->isStudent() && $ticket->status !== QueueTicket::STATUS_WAITING) {
            return $this->fail('This ticket can no longer be cancelled. Please speak to the staff at the counter.', 422);
        }

        if (in_array($ticket->status, [QueueTicket::STATUS_SERVED, QueueTicket::STATUS_CANCELLED], true)) {
            return $this->fail("Ticket {$ticket->queue_number} is already {$ticket->status}.", 422);
        }

        $ticket->update([
            'status' => QueueTicket::STATUS_CANCELLED,
            'called_at' => null,
            'expires_at' => null,
            'arrived_at' => null,
            'cancelled_at' => now(),
            'cancel_reason' => null,
        ]);

        // Cancelling frees a slot in the batch, so the batch may now be
        // complete. Without this the office would keep a finished batch open
        // and refuse new tickets for a batch nobody is left in.
        (new QueueDeploymentManager())->refreshAfterService($ticket);

        // The alert is written only after the cancellation above succeeded,
        // and exactly once per ticket (see TicketCancellationNotifier), so a
        // failed cancel notifies nobody and a retried POST notifies once.
        TicketCancellationNotifier::notify($ticket->fresh(), $user);

        return $this->json([
            'message' => "Ticket {$ticket->queue_number} cancelled.",
            'ticket' => ApiPresenter::ticket($ticket->fresh()),
        ]);
    }

    /**
     * GET /api/v1/tickets/{ticket}/evidence
     *
     * Streams the PWD proof. Served from storage rather than exposed as a
     * public URL because a PWD photo is the most sensitive thing this system
     * holds: it is a person's disability, in a file anyone with the path can
     * read.
     */
    public function evidence(Request $request, QueueTicket $ticket)
    {
        $this->guardTicketAccess($this->account($request), $ticket);

        $path = $ticket->priority_evidence;

        if (! $ticket->isPwd() || blank($path) || ! Storage::disk('local')->exists($path)) {
            $this->refuse('No PWD evidence is attached to this ticket.', 404);
        }

        return Storage::disk('local')->response($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm the account may act on this ticket.
     *
     * A miss is reported as 404 on purpose: see the class docblock.
     */
    private function guardTicketAccess(User $user, QueueTicket $ticket): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->isStaff()) {
            $this->guardOffice($user, $this->operationalOffice($ticket->office_id));

            return;
        }

        if ($ticket->user_id !== $user->id) {
            $this->refuse('That ticket was not found.', 404);
        }
    }

    /**
     * An office-scoped ticket list for staff.
     */
    private function officeTickets(Request $request, Office $office)
    {
        return QueueTicket::where('office_id', $office->id)
            ->with(['office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when(
                $request->boolean('today'),
                fn ($q) => $q->whereDate('created_at', today())
            )
            ->latest('id')
            ->limit(200)
            ->get();
    }

    /**
     * Ticket counters scoped to what the caller is allowed to see.
     *
     * @return array<string, int>
     */
    private function scopedCounts(User $user, Request $request): array
    {
        $base = match (true) {
            $user->isAdmin() && $request->filled('office_id') => QueueTicket::where('office_id', $request->integer('office_id')),
            $user->isAdmin() => QueueTicket::query(),
            default => QueueTicket::where('office_id', optional($this->staffOffice($user))->id),
        };

        $today = (clone $base)->whereDate('created_at', today());

        $count = static fn ($query, string $status): int => (int) (clone $query)
            ->where('status', $status)
            ->count();

        return [
            'total' => (int) $base->count(),
            'waiting' => $count($today, QueueTicket::STATUS_WAITING),
            'serving' => $count($today, QueueTicket::STATUS_SERVING),
            'served' => $count($today, QueueTicket::STATUS_SERVED),
            'cancelled' => $count($today, QueueTicket::STATUS_CANCELLED),
            'no_show' => $count($today, QueueTicket::STATUS_NO_SHOW),
            'today' => (int) $today->count(),
        ];
    }
}