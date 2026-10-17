<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use App\Services\TicketCancellationNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QueueController extends Controller
{
    public function index(Request $request)
    {
        $query = QueueTicket::with(['office', 'student', 'servedByStaff', 'course', 'service', 'station'])->latest();

        $date = $request->input('date', today()->toDateString());

        if ($date) {
            $query->whereDate('created_at', $date);
        }

        if ($request->filled('office_id')) {
            $query->where('office_id', $request->integer('office_id'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->integer('course_id'));
        }

        if (
            $request->filled('priority') &&
            in_array($request->priority, QueueTicket::PRIORITIES, true)
        ) {
            $query->where('priority', $request->priority);
        }

        if (
            $request->filled('status') &&
            in_array($request->status, QueueTicket::STATUSES, true)
        ) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('queue_number', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($student) use ($search) {
                        $student->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $tickets = $query->paginate(15)->withQueryString();

$offices = Office::assignable()->orderBy('name')->get();
        $courses = Course::orderBy('code')->get();

        return view(
            'queues.index',
            compact('tickets', 'offices', 'courses', 'date')
        );
    }

    public function create()
    {
        $offices = Office::with(['services' => fn ($q) => $q->where('is_active', true)->with('station')])->assignable()->orderBy('name')->get();

        $students = User::where('role', User::ROLE_STUDENT)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $courses = Course::where('is_active', true)
            ->orderBy('code')
            ->get();

        return view('queues.create', compact('offices', 'students', 'courses'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'office_id' => ['required', 'exists:offices,id'],
            'service_id' => ['required', 'exists:office_services,id'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'priority' => ['required', 'in:regular,pwd'],
            'priority_evidence' => ['nullable', 'file'],
        ]);

        if (!empty($validated['user_id'])) {
            $student = User::whereKey($validated['user_id'])
                ->where('role', User::ROLE_STUDENT)
                ->where('is_active', true)
                ->firstOrFail();

            $maxActive = (int) Setting::get(
                'max_active_tickets_per_student',
                1
            );

            $activeCount = QueueTicket::where('user_id', $student->id)
                ->whereIn('status', [
                    QueueTicket::STATUS_WAITING,
                    QueueTicket::STATUS_SERVING,
                ])
                ->whereDate('created_at', today())
                ->count();

            if ($activeCount >= $maxActive) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'user_id' =>
                            "This student already has the maximum of {$maxActive} active ticket(s) today.",
                    ]);
            }
        }

        $courseCode = null;
        if (!empty($validated['course_id'])) {
            $courseCode = Course::whereKey($validated['course_id'])->value('code');
        }

        try {
            $result = (new QueueTicketCreator())->create(array_merge($request->only([
                'office_id',
                'service_id',
                'user_id',
                'course_id',
                'priority',
                'priority_evidence',
                'purpose',
            ]), ['student_course' => $courseCode,
                // Atomic backstop for the pre-check above, kept only for
                // tickets issued to a student account.
                'enforce_student_limit' => !empty($validated['user_id']),
            ]));
        } catch (ValidationException $e) {
            return back()
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.queues.index')
            ->with('status', $result['message']);
    }

    public function callNext(Request $request, Office $office)
    {
        abort_unless($office->is_active, 404);

        $validated = $request->validate([
            'call_duration_minutes' => ['nullable', 'integer', 'min:'.QueueTicket::MIN_CALL_DURATION_MINUTES, 'max:'.QueueTicket::MAX_CALL_DURATION_MINUTES],
        ]);

        $duration = QueueTicket::normalizeCallDuration($validated['call_duration_minutes'] ?? null);

        if ($duration === null) {
            return back()->withErrors([
                'call_duration_minutes' => 'Call duration must be between '.QueueTicket::MIN_CALL_DURATION_MINUTES.' and '.QueueTicket::MAX_CALL_DURATION_MINUTES.' minutes.',
            ])->withInput();
        }

        // Single authority: QueueCounter decides ordering, blocking and
        // stamping so admin and staff consoles can never disagree. An
        // expired serving ticket still blocks until resolved via recall /
        // no-show, which is what raises the staff popup.
        try {
            $ticket = (new \App\Services\QueueCounter())->callNext($office, $request->user(), null, $duration);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        if (!$ticket) {
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

    public function updateStatus(Request $request, QueueTicket $ticket)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                'in:' . implode(',', QueueTicket::STATUSES),
            ],
        ]);

        $status = $validated['status'];

        if ($status === QueueTicket::STATUS_SERVING) {
            $hasOtherServing = QueueTicket::where(
                'office_id',
                $ticket->office_id
            )
                ->where('id', '!=', $ticket->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->exists();

            if ($hasOtherServing) {
                return back()->withErrors([
                    'status' =>
                        'Another ticket is already being served in this office.',
                ]);
            }
        }

        $ticket->status = $status;

        if ($status === QueueTicket::STATUS_WAITING) {
            $ticket->served_by = null;
            $ticket->called_at = null;
            $ticket->expires_at = null;
            $ticket->arrived_at = null;
            $ticket->served_at = null;
            $ticket->cancelled_at = null;
            $ticket->cancel_reason = null;
            $ticket->no_show_at = null;
            $ticket->no_show_by = null;
        } elseif ($status === QueueTicket::STATUS_SERVING) {
            $ticket->served_by = Auth::id();
            $ticket->called_at ??= now();
            $ticket->call_duration_minutes ??= QueueTicket::DEFAULT_CALL_DURATION_MINUTES;
            $ticket->expires_at ??= (clone $ticket->called_at)->addMinutes($ticket->effectiveCallDuration());
            $ticket->served_at = null;
        } elseif ($status === QueueTicket::STATUS_SERVED) {
            $ticket->called_at ??= now();
            $ticket->served_at = now();
            $ticket->expires_at = null;
        } elseif ($status === QueueTicket::STATUS_NO_SHOW) {
            $ticket->no_show_at ??= now();
            $ticket->no_show_by ??= Auth::id();
            // Preserve called_at/expires_at for the audit trail; the ticket
            // leaves the live queue via its status, not by clearing stamps.
        } elseif ($status === QueueTicket::STATUS_CANCELLED) {
            $ticket->served_at = null;
            $ticket->called_at = null;
            $ticket->expires_at = null;
            $ticket->arrived_at = null;
            $ticket->cancelled_at ??= now();
        }

        $ticket->save();

        // A batch whose tickets are all processed becomes COMPLETED.
        // No-Show is terminal like served/cancelled: it frees the batch.
        if (in_array($status, [QueueTicket::STATUS_SERVED, QueueTicket::STATUS_CANCELLED, QueueTicket::STATUS_NO_SHOW], true)) {
            (new QueueDeploymentManager())->refreshAfterService($ticket);
        }

        if ($status === QueueTicket::STATUS_CANCELLED) {
            TicketCancellationNotifier::notify($ticket->fresh(), $request->user());
        }

        return back()->with(
            'status',
            "Ticket {$ticket->queue_number} marked as {$status}."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PWD evidence
    |--------------------------------------------------------------------------
    |
    | Evidence is stored outside the public web root and is only streamed back
    | through this authenticated, admin/staff-only route.
    |
    */

    public function evidence(QueueTicket $ticket)
    {
        abort_unless($ticket->isPwd(), 404);

        $path = $ticket->priority_evidence;

        if (!filled($path) || !Storage::disk('local')->exists($path)) {
            abort(404, 'PWD evidence not found.');
        }

        return Storage::disk('local')->response($path);
    }

    public function destroy(QueueTicket $ticket)
    {
        $number = $ticket->queue_number;

        $ticket->delete();

        return back()->with(
            'status',
            "Queue {$number} deleted."
        );
    }

    public function resetDailyQueue()
    {
        $deleted = QueueTicket::whereDate(
            'created_at',
            today()
        )->delete();

        return back()->with(
            'success',
            "{$deleted} queue ticket(s) were removed. Today's queue has been reset."
        );
    }

    public function clearOldQueueRecords()
    {
        $retentionDays = (int) Setting::get(
            'data_retention_period',
            365
        );

        if ($retentionDays < 1) {
            $retentionDays = 365;
        }

        $cutoffDate = now()->subDays($retentionDays);

        $deleted = QueueTicket::where(
            'created_at',
            '<',
            $cutoffDate
        )->delete();

        return back()->with(
            'success',
            "{$deleted} old queue record(s) were removed."
        );
    }

    public function display(Office $office, ?Course $course = null)
    {
        $nowServing = QueueTicket::where(
            'office_id',
            $office->id
        )
            ->where(
                'status',
                QueueTicket::STATUS_SERVING
            )
            ->whereDate(
                'created_at',
                today()
            )
            ->latest('called_at')
            ->first();

        $waiting = QueueTicket::where(
            'office_id',
            $office->id
        )
            ->where(
                'status',
                QueueTicket::STATUS_WAITING
            )
            ->whereDate(
                'created_at',
                today()
            )
            ->orderByRaw("CASE WHEN priority = 'pwd' THEN 0 ELSE 1 END, created_at ASC")
            ->get();

        $deployment = QueueDeployment::activeFor($office);

        $slotsUsed = $deployment?->tickets()->count() ?? 0;
        $slotsMax = $deployment?->max_slots ?? $office->slotLimit();

        return view(
            'queues.display',
            compact(
                'office',
                'course',
                'nowServing',
                'waiting',
                'deployment',
                'slotsUsed',
                'slotsMax'
            )
        );
    }
}
