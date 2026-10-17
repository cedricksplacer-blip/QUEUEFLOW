<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use Illuminate\Http\Request;

class StaffInfoController extends Controller
{
    use StaffOfficeScope;
    /**
     * Active announcements plus the staff member's own submissions.
     * Staff can request an announcement, but it only goes live once
     * the administrator accepts it for verification.
     */
    public function announcements(Request $request)
    {
        $office = $this->officeOf($request);

        $announcements = Announcement::with('author')
            ->publicFor(Announcement::AUDIENCE_STAFF)
            ->latest()
            ->get();

        $myAnnouncements = Announcement::with(['reviewedBy'])
            ->where('submitted_by', $request->user()->id)
            ->latest()
            ->get();

        $readIds = AnnouncementRead::where('user_id', $request->user()->id)
            ->pluck('announcement_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return view('staff.announcements.index', compact('office', 'announcements', 'myAnnouncements', 'readIds'));
    }

    /**
     * Form to request a new announcement for admin verification.
     */
    public function createAnnouncement(Request $request)
    {
        $office = $this->officeOf($request);

        return view('staff.announcements.create', compact('office'));
    }

    /**
     * Submit an announcement request. It is stored as pending and hidden
     * until an administrator accepts it.
     */
    public function storeAnnouncement(Request $request)
    {
        $office = $this->officeOf($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['nullable', 'string', 'in:user,staff,both'],
        ]);

        Announcement::create([
            ...$validated,
            'audience' => $validated['audience'] ?? Announcement::AUDIENCE_BOTH,
            'status' => Announcement::STATUS_PENDING,
            'is_active' => false,
            'office_id' => $office->id,
            'posted_by' => $request->user()->id,
            'submitted_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('staff.announcements.index')
            ->with('status', "Your announcement request for {$office->name} was submitted. It will go live once the administrator accepts it.");
    }

    /**
     * Single admin announcement for staff, scoped to the staff audience.
     *
     * Viewing marks it as read so the bell badge clears for announcements
     * the staff member has actually opened.
     */
    public function showAnnouncement(Request $request, Announcement $announcement)
    {
        $this->guardStaffVisible($announcement);

        $announcement->loadMissing(['author', 'office', 'reviewedBy']);

        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['read_at' => now()]
        );

        $isRead = true;

        return view('staff.announcements.show', compact('announcement', 'isRead'));
    }

    /**
     * Polling feed for the header bell: latest admin announcements for the
     * staff audience plus the unread count.
     *
     * Only published + active announcements with a staff-visible audience
     * (Staff Only or User and Staff) are returned, so User Only notices
     * never reach the staff bell even if their id is guessed.
     */
    public function announcementFeed(Request $request)
    {
        $user = $request->user();

        $announcements = Announcement::with(['author', 'office', 'reads'])
            ->publicFor(Announcement::AUDIENCE_STAFF)
            ->latest()
            ->limit(10)
            ->get();

        $payload = $announcements->map->toBellArray($user)->values();

        $unreadCount = $this->staffAnnouncementUnreadCount($user);

        if ($request->expectsJson()) {
            return response()->json([
                'notifications' => $payload,
                'unread_count' => $unreadCount,
            ]);
        }

        return back();
    }

    public function announcementUnreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => $this->staffAnnouncementUnreadCount($request->user()),
        ]);
    }

    /**
     * Mark one staff-visible announcement as read.
     */
    public function markAnnouncementRead(Request $request, Announcement $announcement)
    {
        $this->guardStaffVisible($announcement);

        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['read_at' => now()]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Announcement marked as read.',
                'notification' => $announcement->fresh()->toBellArray($request->user()),
            ]);
        }

        return back()->with('status', 'Announcement marked as read.');
    }

    /**
     * Mark every currently staff-visible announcement as read.
     */
    public function markAllAnnouncementsRead(Request $request)
    {
        $user = $request->user();

        $visibleIds = Announcement::publicFor(Announcement::AUDIENCE_STAFF)
            ->pluck('id');

        $already = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', $visibleIds)
            ->pluck('announcement_id')
            ->all();

        $missing = $visibleIds->diff($already);

        $now = now();

        $rows = $missing->map(fn ($id) => [
            'announcement_id' => $id,
            'user_id' => $user->id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            AnnouncementRead::insertOrIgnore($rows);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'All announcements marked as read.',
            ]);
        }

        return back()->with('status', 'All announcements marked as read.');
    }

    /**
     * 404 unless the announcement is published, active and staff-visible.
     */
    private function guardStaffVisible(Announcement $announcement): void
    {
        abort_unless($announcement->status === Announcement::STATUS_PUBLISHED, 404);
        abort_unless((bool) $announcement->is_active, 404);
        abort_unless($announcement->isVisibleTo(Announcement::AUDIENCE_STAFF), 404);
    }

    private function staffAnnouncementUnreadCount($user): int
    {
        $visibleIds = Announcement::publicFor(Announcement::AUDIENCE_STAFF)
            ->pluck('id');

        if ($visibleIds->isEmpty()) {
            return 0;
        }

        $read = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', $visibleIds)
            ->count();

        return max(0, $visibleIds->count() - $read);
    }

    /**
     * Complete queue record for the staff member's office: every current
     * (waiting/serving, including call-expired) and past (served,
     * cancelled, no-show) ticket, newest first.
     *
     * Always scoped to the staff member's own office via officeOf(), so one
     * office's records never leak into another's. No date restriction by
     * default — history survives batch close/complete/reset because those
     * operations only mark deployments completed and never delete tickets.
     * Optional date/batch/status/search filters narrow the same query, and
     * pagination keeps each page free of duplicates.
     */
    public function history(Request $request)
    {
        $office = $this->officeOf($request);

        $date = $request->filled('date') ? trim((string) $request->input('date')) : null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = null;
        }

        $batch = $request->filled('batch') && $request->input('batch') !== '' ? (int) $request->input('batch') : null;
        $status = $request->filled('status') && in_array($request->input('status'), QueueTicket::STATUSES, true) ? $request->input('status') : null;
        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;

        $base = $this->historyScope(QueueTicket::query(), $office->id, $date, $batch, $search)
            ->when($status, fn ($q) => $q->where('status', $status));

        $tickets = (clone $base)
            ->with(['office', 'student', 'course', 'servedByStaff', 'noShowByStaff', 'service', 'station'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        // Summary cards follow the date/batch/search filters (but never the
        // status filter) so they always break down the visible record set.
        $statBase = $this->historyScope(QueueTicket::query(), $office->id, $date, $batch, $search);

        $stats = [
            'total' => (clone $statBase)->count(),
            'waiting' => (clone $statBase)->where('status', QueueTicket::STATUS_WAITING)->count(),
            'serving' => (clone $statBase)->where('status', QueueTicket::STATUS_SERVING)->count(),
            'served' => (clone $statBase)->where('status', QueueTicket::STATUS_SERVED)->count(),
            'cancelled' => (clone $statBase)->where('status', QueueTicket::STATUS_CANCELLED)->count(),
            'no_show' => (clone $statBase)->where('status', QueueTicket::STATUS_NO_SHOW)->count(),
        ];

        $batches = QueueDeployment::where('office_id', $office->id)
            ->when($date, fn ($q) => $q->whereHas('tickets', fn ($t) => $t->whereDate('created_at', $date)))
            ->orderBy('batch_no')
            ->pluck('batch_no');

        return view('staff.history.index', compact('office', 'tickets', 'date', 'batch', 'batches', 'stats', 'status', 'search'));
    }

    /**
     * Shared office/date/batch/search scope for the history list and its
     * summary cards. Single-table conditions only, so rows can never
     * duplicate across pages.
     */
    private function historyScope($query, int $officeId, ?string $date, ?int $batch, ?string $search)
    {
        return $query
            ->where('office_id', $officeId)
            ->when($date, fn ($q) => $q->whereDate('created_at', $date))
            ->when($batch, fn ($q) => $q->whereHas('deployment', fn ($d) => $d->where('batch_no', $batch)))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('queue_number', 'like', "%{$search}%")
                        ->orWhere('purpose', 'like', "%{$search}%")
                        ->orWhereHas('student', function ($student) use ($search) {
                            $student->where('name', 'like', "%{$search}%")
                                ->orWhere('student_id', 'like', "%{$search}%");
                        });
                });
            });
    }

    /**
     * Office profile card + admin-managed information scoped to this office.
     */
    public function office(Request $request)
    {
        $office = $this->officeOf($request);

        $office->load([
            'staff' => fn ($q) => $q->where('is_active', true)->orderBy('name'),
            'courses' => fn ($q) => $q->where('is_active', true)->with('assignedStaff')->orderBy('code'),
            'stations' => fn ($q) => $q->orderBy('name'),
            'services' => fn ($q) => $q->orderBy('name'),
            'activeDeployment',
        ]);

        $lastDeployment = QueueDeployment::where('office_id', $office->id)
            ->latest('id')
            ->first();

        return view('staff.office', compact('office', 'lastDeployment'));
    }

    /**
     * Flip the online/offline switch for the staff member's own office.
     *
     * Scoped through StaffOfficeScope::officeOf(), so a staff token can only
     * toggle the one office it is assigned to. Each Registrar counter flips
     * alone, matching the admin screens and the API.
     */
    public function toggleStatus(Request $request)
    {
        $office = $this->officeOf($request);

        $requested = $request->input('status');

        $status = in_array($requested, [Office::STATUS_ONLINE, Office::STATUS_OFFLINE], true)
            ? $requested
            : ($office->isOnline() ? Office::STATUS_OFFLINE : Office::STATUS_ONLINE);

        $updated = $office->applyStatusToGroup($status);
        $message = $updated > 0
            ? Office::statusMessage($office, $status)
            : 'The office status is unchanged.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'status' => $status,
                'offices' => $updated,
            ]);
        }

        return back()->with('status', $message);
    }
}