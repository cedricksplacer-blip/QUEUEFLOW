<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today();

        $stats = [
            'total_today' => QueueTicket::whereDate('created_at', $today)->count(),
            'waiting' => QueueTicket::whereDate('created_at', $today)->where('status', QueueTicket::STATUS_WAITING)->count(),
            'serving' => QueueTicket::whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVING)->count(),
            'served' => QueueTicket::whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVED)->count(),
            'no_show' => QueueTicket::whereDate('created_at', $today)->where('status', QueueTicket::STATUS_NO_SHOW)->count(),
            'staff' => User::where('role', User::ROLE_STAFF)->where('is_active', true)->count(),
            'students' => User::where('role', User::ROLE_STUDENT)->where('is_active', true)->count(),
            'offices' => Office::where('is_active', true)->count(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Per-office queue statistics
        |--------------------------------------------------------------------------
        |
        | Real database figures for EVERY active office — the official
        | structure plus any custom office the admin added via Offices.
        | A newly created office appears here automatically on the next
        | render/poll with zeroed counters; renaming updates the name;
        | deactivating hides the row (consistent with Offices management).
        | Counts are scoped to today (app timezone) by ticket status:
        | waiting/serving hold only live tickets, served holds finished
        | ones, and tickets_today follows the existing reporting rule
        | (every ticket created today, any status).
        |
        */

        $officeStats = Office::where('is_active', true)
            ->with('activeDeployment')
            ->withCount([
                'queueTickets as tickets_today' => fn ($q) => $q->whereDate('created_at', $today),
                'queueTickets as waiting_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_WAITING),
                'queueTickets as serving_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVING),
                'queueTickets as served_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVED),
                'queueTickets as cancelled_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_CANCELLED),
                'queueTickets as no_show_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_NO_SHOW),
            ])
            ->orderBy('name')
            ->get();

        /*
        |----------------------------------------------------------------------
        | Campus office status (online/offline) per counter
        |----------------------------------------------------------------------
        |
        | Every operational office gets its own row — including each per-course
        | Registrar counter, whose switch belongs to the staff assigned to that
        | counter alone. Registrar rows sort first (by code), then the single
        | offices in campus order.
        |
        */

        $officeOrder = collect([
            'Cashier',
            'Admissions',
            'SASO',
            'Scholarship Office',
            'School Clinic',
        ]);

        $officeStatus = Office::where('is_active', true)
            ->get(['id', 'name', 'code', 'status'])
            ->map(fn (Office $office) => [
                'key' => $office->code,
                'name' => $office->name,
                'code' => $office->code,
                'status' => $office->status,
                'office_id' => $office->id,
            ])
            ->sortBy(function ($row) use ($officeOrder) {
                if (str_starts_with($row['code'], 'REG-') || $row['code'] === 'REG') {
                    return '0_'.$row['code'];
                }

                $rank = $officeOrder->search($row['name']);

                return '1_'.($rank === false ? $officeOrder->count() : $rank).'_'.$row['code'];
            })
            ->values();

        $recentQueues = QueueTicket::with(['office', 'student', 'servedByStaff'])
            ->latest()
            ->take(10)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Recent queue activity, grouped per office (Registrar per course)
        |--------------------------------------------------------------------------
        |
        | The six groups the admin console manages: Registrar (seven course
        | desks) plus Cashier, Admissions, SASO, Scholarship and Clinic. Each
        | group carries its latest tickets with the relations the dashboard
        | rows render; Registrar additionally carries one list per active
        | course so a BSIT ticket can never appear under BSOA. Everything is
        | read from the database — no hardcoded rows.
        |
        */

        $activityGroups = $this->activityGroups();

        $announcements = Announcement::with('author')
            ->public()
            ->latest()
            ->take(5)
            ->get();

        // Hourly ticket volume for the last 8 hours, used by the animated
        // "Queue Volume" line chart on the dashboard.
        $hourly = collect(range(7, 0))->map(function ($hoursAgo) {
            $slot = now()->subHours($hoursAgo);
            $count = QueueTicket::whereBetween('created_at', [
                $slot->copy()->startOfHour(),
                $slot->copy()->endOfHour(),
            ])->count();

            return [
                'label' => $slot->format('ga'),
                'count' => $count,
            ];
        })->values();

        return view('admin.dashboard', compact('stats', 'officeStats', 'recentQueues', 'announcements', 'hourly', 'officeStatus', 'activityGroups'));
    }

    /**
     * Polling feed for the dashboard activity accordion. Same rows as the
     * server-rendered groups, as JSON; the page re-renders ticket lists
     * without a reload while keeping each group's open/closed state.
     */
    public function recentActivity()
    {
        return response()->json([
            'groups' => array_map(
                fn (array $group) => $this->groupToArray($group),
                $this->activityGroups()
            ),
        ]);
    }

    /**
     * Lightweight polling feed for the dashboard's live numbers: per-office
     * queue counters plus each counter's online/offline switch, and the
     * campus-wide totals. Same queries as index(), no extra tables, so the
     * stat cards and status pills can refresh in place without a reload.
     */
    public function queueSummary()
    {
        $today = today();

        $offices = Office::where('is_active', true)
            ->with('activeDeployment')
            ->withCount([
                'queueTickets as tickets_today' => fn ($q) => $q->whereDate('created_at', $today),
                'queueTickets as waiting_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_WAITING),
                'queueTickets as serving_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVING),
                'queueTickets as served_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_SERVED),
                'queueTickets as cancelled_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_CANCELLED),
                'queueTickets as no_show_count' => fn ($q) => $q->whereDate('created_at', $today)->where('status', QueueTicket::STATUS_NO_SHOW),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->map(fn (Office $office) => [
                'id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'status' => $office->status ?: Office::STATUS_OFFLINE,
                'display_url' => route('queues.display', $office),
                'batch_no' => $office->activeDeployment?->batch_no,
                'batch_status' => $office->activeDeployment?->status,
                'tickets_today' => (int) $office->tickets_today,
                'waiting' => (int) $office->waiting_count,
                'serving' => (int) $office->serving_count,
                'served' => (int) $office->served_count,
                'cancelled' => (int) $office->cancelled_count,
                'no_show' => (int) $office->no_show_count,
            ])
            ->values();

        $base = QueueTicket::whereDate('created_at', $today);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'stats' => [
                'total_today' => (int) (clone $base)->count(),
                'waiting' => (int) (clone $base)->where('status', QueueTicket::STATUS_WAITING)->count(),
                'serving' => (int) (clone $base)->where('status', QueueTicket::STATUS_SERVING)->count(),
                'served' => (int) (clone $base)->where('status', QueueTicket::STATUS_SERVED)->count(),
            ],
            'offices' => $offices,
        ]);
    }

    /**
     * Minimal change detector for queue list pages (Queue Management,
     * Queue Activity History, Cancellation Alerts). Returns the newest
     * ticket id (optionally scoped to one office) plus today's total, so a
     * page can show a "new updates available" banner without re-rendering
     * filtered tables or touching form state.
     */
    public function queuePing(Request $request)
    {
        $query = QueueTicket::query();

        if ($request->filled('office_id')) {
            $query->where('office_id', $request->integer('office_id'));
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'latest_ticket_id' => (int) $query->max('id'),
            'today_total' => (int) (clone $query)->whereDate('created_at', today())->count(),
            'latest_notification_id' => (int) StaffNotification::max('id'),
        ]);
    }

    /**
     * Queue Activity History: every ticket from every office and Registrar
     * counter in one centralized list, latest first.
     *
     * This is the dashboard "View all" target. It reads the same
     * queue_tickets rows as everything else — no separate activity store —
     * with the same office/course/status/priority/search filters as Queue
     * Management, but no date restriction by default so the complete history
     * shows. Paginated; a fresh visit always reflects new tickets.
     */
    public function activity(Request $request)
    {
        $query = QueueTicket::with(['office', 'student', 'course', 'service', 'station'])->latest();

        $date = $request->input('date');

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
                        $student->where('name', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    });
            });
        }

        $activities = $query->paginate(15)->withQueryString();

        $offices = Office::where('is_active', true)->orderBy('name')->get();
        $courses = Course::orderBy('code')->get();

        return view('admin.activity', compact('activities', 'offices', 'courses', 'date'));
    }

    /**
     * The six office groups in console order, each with its latest tickets.
     *
     * @return array<int, array{key: string, name: string, tickets: \Illuminate\Support\Collection, courses: array}>
     */
    private function activityGroups(): array
    {
        // Every active office gets activity coverage: the official groups
        // keep their console order, then any custom office the admin added
        // appears as its own group (sorted by name) so its tickets are
        // visible without manual wiring.
        $offices = Office::where('is_active', true)
            ->get(['id', 'name', 'code']);

        $order = [
            Office::REGISTRAR_GROUP,
            'CASH',
            'ADM',
            'SASO',
            'SCH',
            'CLINIC',
        ];

        $byGroup = $offices->groupBy(fn (Office $office) => $office->activityGroupKey());

        $courses = Course::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $groups = [];

        foreach ($order as $key) {
            $groupOffices = $byGroup->get($key, collect());

            if ($groupOffices->isEmpty()) {
                continue;
            }

            $officeIds = $groupOffices->pluck('id')->all();

            $tickets = QueueTicket::with(['office', 'student', 'course', 'service'])
                ->whereIn('office_id', $officeIds)
                ->latest()
                ->take(6)
                ->get();

            $courseLists = [];

            if ($key === Office::REGISTRAR_GROUP) {
                foreach ($courses as $course) {
                    $courseLists[] = [
                        'id' => $course->id,
                        'code' => $course->code,
                        'name' => $course->name,
                        'tickets' => QueueTicket::with(['office', 'student', 'course', 'service'])
                            ->whereIn('office_id', $officeIds)
                            ->where('course_id', $course->id)
                            ->latest()
                            ->take(4)
                            ->get(),
                    ];
                }
            }

            $groups[] = [
                'key' => $key,
                'name' => $key === Office::REGISTRAR_GROUP ? 'Registrar' : $groupOffices->first()->groupLabel(),
                'tickets' => $tickets,
                'courses' => $courseLists,
            ];
        }

        // Custom offices added via Offices: one group each, sorted by name.
        $extraKeys = $byGroup->keys()->diff($order)->sort()->values();

        foreach ($extraKeys as $key) {
            $groupOffices = $byGroup->get($key);

            if ($groupOffices->isEmpty()) {
                continue;
            }

            $officeIds = $groupOffices->pluck('id')->all();

            $tickets = QueueTicket::with(['office', 'student', 'course', 'service'])
                ->whereIn('office_id', $officeIds)
                ->latest()
                ->take(6)
                ->get();

            $groups[] = [
                'key' => $key,
                'name' => $groupOffices->first()->groupLabel(),
                'tickets' => $tickets,
                'courses' => [],
            ];
        }

        return $groups;
    }

    /**
     * JSON form of one activity group for the polling feed.
     */
    private function groupToArray(array $group): array
    {
        return [
            'key' => $group['key'],
            'name' => $group['name'],
            'tickets' => $group['tickets']->map(fn (QueueTicket $ticket) => $this->ticketToArray($ticket))->values(),
            'courses' => array_map(
                fn (array $course) => [
                    'id' => $course['id'],
                    'code' => $course['code'],
                    'name' => $course['name'],
                    'tickets' => $course['tickets']->map(fn (QueueTicket $ticket) => $this->ticketToArray($ticket))->values(),
                ],
                $group['courses']
            ),
        ];
    }

    /**
     * JSON form of one ticket row. Evidence is referenced by route, never by
     * storage path: the file streams through the admin-only evidence
     * endpoint, so no private URL is exposed.
     */
    private function ticketToArray(QueueTicket $ticket): array
    {
        $hasEvidence = $ticket->isPwd() && filled($ticket->priority_evidence);

        return [
            'id' => $ticket->id,
            'queue_number' => $ticket->queue_number,
            'office' => $ticket->office?->name,
            'course' => $ticket->course?->code,
            'student' => $ticket->student?->name ?? 'Walk-in',
            'student_id' => $ticket->student?->student_id,
            'purpose' => $ticket->purpose,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'has_evidence' => $hasEvidence,
            'evidence_url' => $hasEvidence
                ? route('admin.queues.evidence', $ticket)
                : null,
            'created_at' => optional($ticket->created_at)->toIso8601String(),
            'time' => optional($ticket->created_at)->format('M d, h:i A'),
        ];
    }
}