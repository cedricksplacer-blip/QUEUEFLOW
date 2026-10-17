<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Http\Controllers\Traits\StaffPreferences;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use Illuminate\Http\Request;

class StaffDashboardController extends Controller
{
    use StaffOfficeScope, StaffPreferences;
    /**
     * Staff dashboard scoped to the staff member's assigned office.
     *
     * Mirrors the admin dashboard figures but only for this office, since
     * staff accounts are linked to exactly one operational office.
     */
    public function index(Request $request)
    {
        $office = $this->officeOf($request);

        $today = today();

        $deployment = QueueDeployment::activeFor($office);

        $nowServing = QueueTicket::with(['student', 'course'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', $today)
            ->latest('called_at')
            ->first();

        $waiting = QueueTicket::with(['student', 'course'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->whereDate('created_at', $today)
            ->orderByRaw("CASE WHEN priority = 'pwd' THEN 0 ELSE 1 END, created_at ASC")
            ->get();

        $recentServed = QueueTicket::with(['student', 'course'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVED)
            ->whereDate('created_at', $today)
            ->orderByDesc('served_at')
            ->limit(8)
            ->get();

        $recentNoShow = QueueTicket::with(['student', 'course', 'noShowByStaff'])
            ->where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_NO_SHOW)
            ->whereDate('created_at', $today)
            ->orderByDesc('no_show_at')
            ->limit(8)
            ->get();

        $noShowCount = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_NO_SHOW)
            ->whereDate('created_at', $today)
            ->count();

        if ($deployment) {
            $deployment->waiting_count = QueueTicket::where('deployment_id', $deployment->id)
                ->where('status', QueueTicket::STATUS_WAITING)
                ->count();
            $deployment->serving_count = QueueTicket::where('deployment_id', $deployment->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->count();
        }

        $lastDeployment = QueueDeployment::where('office_id', $office->id)
            ->latest('id')
            ->first();

        $showNames = $this->staffPref($request->user(), 'show_names', '1') === '1';
        $confirmServed = $this->staffPref($request->user(), 'confirm_served', '1') === '1';

        $callTimeout = $office->callTimeoutMinutes();

        $cancellationAlerts = StaffNotification::with(['ticket'])
            ->forOffice($office->id)
            ->cancelled()
            ->recent()
            ->limit(5)
            ->get();

        $cancellationUnread = StaffNotification::forOffice($office->id)
            ->cancelled()
            ->unread()
            ->count();

        // The number the next deployment will carry (1 right after a batch
        // reset, then 2, 3, ...). Shown on the deploy controls so the label
        // always matches what the backend will actually store.
        $nextBatchNo = QueueDeployment::nextBatchNoFor($office);

        return view(
            'staff.dashboard',
            compact('office', 'deployment', 'lastDeployment', 'nowServing', 'waiting', 'recentServed', 'recentNoShow', 'noShowCount', 'showNames', 'confirmServed', 'callTimeout', 'cancellationAlerts', 'cancellationUnread', 'nextBatchNo')
        );
    }
}