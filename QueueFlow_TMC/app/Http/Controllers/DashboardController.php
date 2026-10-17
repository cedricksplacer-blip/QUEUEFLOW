<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $officesQuery = Office::query()->where('is_active', true);

        // Staff only see their own office's numbers on the dashboard.
        if ($user->isStaff() && $user->office_id) {
            $officesQuery->where('id', $user->office_id);
        }

        $offices = $officesQuery->withCount([
            'queueTickets as waiting_count' => fn ($q) => $q->where('status', QueueTicket::STATUS_WAITING)->whereDate('created_at', today()),
            'queueTickets as serving_count' => fn ($q) => $q->where('status', QueueTicket::STATUS_SERVING)->whereDate('created_at', today()),
            'queueTickets as served_count' => fn ($q) => $q->where('status', QueueTicket::STATUS_SERVED)->whereDate('created_at', today()),
        ])->get();

        $stats = [
            'total_today' => QueueTicket::whereDate('created_at', today())->count(),
            'waiting_today' => QueueTicket::whereDate('created_at', today())->where('status', QueueTicket::STATUS_WAITING)->count(),
            'served_today' => QueueTicket::whereDate('created_at', today())->where('status', QueueTicket::STATUS_SERVED)->count(),
            'total_offices' => Office::where('is_active', true)->count(),
            'total_staff' => User::where('role', User::ROLE_STAFF)->count(),
            'total_students' => User::where('role', User::ROLE_STUDENT)->count(),
        ];

        $audience = $user->isStaff()
            ? Announcement::AUDIENCE_STAFF
            : ($user->isStudent() ? Announcement::AUDIENCE_USER : null);

        $announcements = Announcement::publicFor($audience)->latest()->take(5)->get();

        $recentTickets = QueueTicket::with(['office', 'student'])
            ->when($user->isStaff() && $user->office_id, fn ($q) => $q->where('office_id', $user->office_id))
            ->latest()
            ->take(8)
            ->get();

        return view('dashboard', compact('offices', 'stats', 'announcements', 'recentTickets'));
    }
}
