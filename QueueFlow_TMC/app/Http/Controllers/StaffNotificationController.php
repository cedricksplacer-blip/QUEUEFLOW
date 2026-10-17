<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Models\StaffNotification;
use Illuminate\Http\Request;

class StaffNotificationController extends Controller
{
    use StaffOfficeScope;

    /**
     * Cancellation history for the staff member's own office.
     */
    public function index(Request $request)
    {
        $office = $this->officeOf($request);

        $filter = $request->input('filter', 'all');

        $base = StaffNotification::with(['ticket.student', 'triggeredBy'])
            ->forOffice($office->id)
            ->cancelled();

        $notifications = (clone $base)
            ->when($filter === 'unread', fn ($q) => $q->unread())
            ->recent()
            ->paginate(15)
            ->withQueryString();

        $unreadCount = (clone $base)->unread()->count();
        $totalCount = (clone $base)->count();

        return view('staff.notifications.index', compact(
            'office',
            'notifications',
            'unreadCount',
            'totalCount',
            'filter'
        ));
    }

    /**
     * Detail for one alert, scoped to the staff member's office.
     */
    public function show(Request $request, StaffNotification $notification)
    {
        $office = $this->officeOf($request);

        abort_unless($notification->office_id === $office->id, 404);

        $notification->loadMissing(['ticket.student', 'ticket.office', 'ticket.service', 'triggeredBy']);

        return view('staff.notifications.show', compact('office', 'notification'));
    }

    /**
     * Polling feed for the bell dropdown: latest alerts + unread count.
     */
    public function recent(Request $request)
    {
        $office = $this->officeOf($request);

        $notifications = StaffNotification::with(['ticket'])
            ->forOffice($office->id)
            ->cancelled()
            ->recent()
            ->limit(10)
            ->get();

        $unreadCount = StaffNotification::forOffice($office->id)
            ->cancelled()
            ->unread()
            ->count();

        if ($request->expectsJson()) {
            return response()->json([
                'notifications' => $notifications->map->toPanelArray()->values(),
                'unread_count' => $unreadCount,
            ]);
        }

        return back();
    }

    public function unreadCount(Request $request)
    {
        $office = $this->officeOf($request);

        return response()->json([
            'unread_count' => StaffNotification::forOffice($office->id)
                ->cancelled()
                ->unread()
                ->count(),
        ]);
    }

    public function markRead(Request $request, StaffNotification $notification)
    {
        $office = $this->officeOf($request);

        abort_unless($notification->office_id === $office->id, 404);

        $notification->markAsRead($request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read.',
                'notification' => $notification->fresh()->toPanelArray(),
            ]);
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $office = $this->officeOf($request);

        StaffNotification::forOffice($office->id)
            ->cancelled()
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'read_by' => $request->user()->id,
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'All cancellation alerts marked as read.',
            ]);
        }

        return back()->with('status', 'All cancellation alerts marked as read.');
    }
}
