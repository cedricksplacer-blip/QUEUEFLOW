<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentQueueController extends Controller
{
    public function index(Request $request)
    {
        $tickets = $request->user()->queueTickets()
            ->with(['office', 'course', 'service', 'station', 'deployment'])
            ->latest()
            ->get();

        return view('student.queues.index', compact('tickets'));
    }

    public function create()
    {
        $user = auth()->user();

        $profileCourse = null;
        if ($user->course) {
            $profileCourse = Course::where('is_active', true)
                ->where('code', $user->course)
                ->first();
        }

        $offices = Office::with([
            'courses' => fn ($q) => $q->where('is_active', true),
            'services' => fn ($q) => $q->where('is_active', true)->with('station'),
        ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(function (Office $office) use ($profileCourse) {
                // Registrar course offices: only surface the one that matches
                // the student's own course whenever a profile course exists.
                if (str_starts_with($office->code, 'REG-')) {
                    if (! $profileCourse) {
                        return true;
                    }

                    return $office->courses->contains(
                        fn ($c) => strcasecmp($c->code, $profileCourse->code) === 0
                    );
                }

                return true;
            })
            ->map(function (Office $office) use ($profileCourse) {
                /** @var Course|null */
                $course = str_starts_with($office->code, 'REG-')
                    ? $office->courses->first()
                    : $profileCourse;

                $open = QueueDeployment::openFor($office);

                return [
                    'office' => $office,
                    'course' => $course,
                    'open' => $open,
                    // An office the assigned staff member switched offline cannot
                    // take numbers even inside its window, so the student web
                    // form agrees with the API and the app.
                    'online' => $office->isOnline(),
                    'submitting' => $office->isOnline() && $open && $open->isOpen() && QueueDeployment::officeWindowIsOpen($office),
                    'window_open' => QueueDeployment::officeWindowIsOpen($office),
                ];
            })
            ->values();

        return view('student.queues.create', compact('offices'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isStudent(), 403);

        $validated = $request->validate([
            'office_id' => ['required', 'exists:offices,id'],
            'course_id' => ['required', 'exists:courses,id'],
            'service_id' => ['required', 'exists:office_services,id'],
            'priority' => ['required', 'in:regular,pwd'],
            'priority_evidence' => ['nullable', 'file'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'in:1st Year,2nd Year,3rd Year,4th Year'],
        ]);

        $maxActive = (int) Setting::get('max_active_tickets_per_student', 1);

        $activeCount = QueueTicket::where('user_id', $request->user()->id)
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
                    'office_id' => "You already have the maximum of {$maxActive} active ticket(s) today.",
                ]);
        }

        $course = Course::whereKey($validated['course_id'])->where('is_active', true)->firstOrFail();

        // Keep the student's selected course/year-level profile aligned with the queue request.
        if ($request->user()->course && strtoupper($request->user()->course) !== strtoupper($course->code)) {
            return back()->withInput()->withErrors([
                'course_id' => 'Your selected course does not match your student profile.',
            ]);
        }

        try {
            $result = (new QueueTicketCreator())->create([
                'office_id' => $validated['office_id'],
                'service_id' => $validated['service_id'],
                'student_course' => $course->code,
                'user_id' => $request->user()->id,
                'course_id' => $validated['course_id'] ?? null,
                'priority' => $validated['priority'],
                'priority_evidence' => $request->file('priority_evidence'),
                'purpose' => $validated['purpose'] ?? null,
                'year_level' => $request->user()->year_level ?: ($validated['year_level'] ?? null),
                // Atomic backstop for the pre-check above: two submits in the
                // same moment must not both issue.
                'enforce_student_limit' => true,
            ]);
        } catch (ValidationException $e) {
            return back()
                ->withInput()
                ->withErrors($e->errors());
        }

        $ticket = $result['ticket'];

        return redirect()
            ->route('student.queues.show', $ticket)
            ->with('status', $result['message']);
    }

    public function show(Request $request, QueueTicket $ticket)
    {
        abort_unless($request->user()->isStudent(), 403);
        abort_unless($ticket->user_id === $request->user()->id, 404);

        $ticket->load(['office', 'course', 'service', 'station', 'deployment', 'servedByStaff']);

        $waitingTime = (int) Setting::get('default_waiting_time', 5);

        $waitingBefore = QueueTicket::where('office_id', $ticket->office_id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->where('created_at', '<', $ticket->created_at)
            ->whereDate('created_at', today())
            ->count();

        $estimatedWaitMinutes = $waitingBefore * $waitingTime;

        return view('student.queues.show', compact('ticket', 'estimatedWaitMinutes'));
    }
}