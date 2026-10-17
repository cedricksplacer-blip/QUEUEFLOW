<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\StaffNotification;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    /**
     * Campus-wide cancellation activity. Administrators see every office;
     * an optional office_id narrows to one counter.
     */
    public function index(Request $request)
    {
        $offices = Office::whereIn('code', Office::OPERATIONAL_CODES)
            ->orderBy('name')
            ->get();

        $officeId = $request->integer('office_id') ?: null;
        $filter = $request->input('filter', 'all');

        $base = StaffNotification::with(['office', 'ticket.student'])
            ->cancelled()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));

        $notifications = (clone $base)
            ->when($filter === 'unread', fn ($q) => $q->unread())
            ->recent()
            ->paginate(20)
            ->withQueryString();

        return view('admin.notifications.index', compact(
            'notifications',
            'offices',
            'officeId',
            'filter'
        ));
    }

    public function show(StaffNotification $notification)
    {
        $notification->loadMissing(['office', 'ticket.student', 'ticket.office', 'ticket.service', 'triggeredBy']);

        return view('admin.notifications.show', compact('notification'));
    }

    public function unreadCount(Request $request)
    {
        $query = StaffNotification::cancelled()->unread();

        if ($request->filled('office_id')) {
            $query->where('office_id', $request->integer('office_id'));
        }

        return response()->json([
            'unread_count' => $query->count(),
        ]);
    }

    public function recent(Request $request)
    {
        $query = StaffNotification::with(['office'])
            ->cancelled()
            ->when(
                $request->filled('office_id'),
                fn ($q) => $q->where('office_id', $request->integer('office_id'))
            );

        $notifications = (clone $query)->recent()->limit(10)->get();
        $unreadCount = (clone $query)->unread()->count();

        return response()->json([
            'notifications' => $notifications->map->toPanelArray()->values(),
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Request $request, StaffNotification $notification)
    {
        $notification->markAsRead($request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read.',
            ]);
        }

        return back()->with('status', 'Notification marked as read.');
    }
}
