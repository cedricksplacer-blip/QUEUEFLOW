<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\OfficeStation;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? now()->subDays(6)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        // Every figure on this page is fused across the real offices only, so
        // the six summary cards always reconcile with the by-office table.
        $operationalOfficeIds = Office::whereIn('code', Office::OPERATIONAL_CODES)->pluck('id');

        $baseQuery = QueueTicket::whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereIn('office_id', $operationalOfficeIds);

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'waiting' => (clone $baseQuery)->where('status', QueueTicket::STATUS_WAITING)->count(),
            'serving' => (clone $baseQuery)->where('status', QueueTicket::STATUS_SERVING)->count(),
            'served' => (clone $baseQuery)->where('status', QueueTicket::STATUS_SERVED)->count(),
            'cancelled' => (clone $baseQuery)->where('status', QueueTicket::STATUS_CANCELLED)->count(),
            'no_show' => (clone $baseQuery)->where('status', QueueTicket::STATUS_NO_SHOW)->count(),
            'pwd' => (clone $baseQuery)->where('priority', QueueTicket::PRIORITY_PWD)->count(),
        ];

        $byOffice = Office::whereIn('code', Office::OPERATIONAL_CODES)
            ->withCount([
                'queueTickets as total_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to),
                'queueTickets as served_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->where('status', QueueTicket::STATUS_SERVED),
                'queueTickets as cancelled_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->where('status', QueueTicket::STATUS_CANCELLED),
                'queueTickets as no_show_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->where('status', QueueTicket::STATUS_NO_SHOW),
            ])->orderByDesc('total_tickets')->get();

        $byStation = OfficeStation::with('office')
            ->whereHas('office', fn ($q) => $q->whereIn('code', Office::OPERATIONAL_CODES))
            ->withCount([
                'queueTickets as total_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to),
                'queueTickets as served_tickets' => fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->where('status', QueueTicket::STATUS_SERVED),
            ])
            ->where('is_active', true)
            ->orderBy('office_id')
            ->orderBy('name')
            ->get();

        $dailyVolume = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('reports.index', compact('summary', 'byOffice', 'byStation', 'dailyVolume', 'from', 'to'));
    }
}
