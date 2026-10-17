@extends('layouts.staff')

@section('title', 'Cancellation Detail')

@section('content')
<div class="qf-dashboard w-full max-w-[1080px] mx-auto px-3 sm:px-4 min-w-0">

    <div class="mb-4">
        <a href="{{ route('staff.notifications.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">← Back to cancellation alerts</a>
    </div>

    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-slate-900">{{ $notification->title }}</h3>
                <p class="text-[11px] text-slate-500 mt-1">{{ $notification->message }}</p>
            </div>
            @if($notification->is_read)
                <span class="badge badge-served">read</span>
            @else
                <span class="badge badge-cancelled">unread</span>
            @endif
        </div>

        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Student Name</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ $notification->student_name ?? '—' }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Student ID</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ $notification->student_id_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Ticket Number</div>
                <div class="font-semibold text-red-700 mt-0.5">{{ $notification->queue_number ?? '—' }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Office</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ $notification->office_name }} ({{ $notification->office_code }})</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Purpose of Visit</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ $notification->purpose ?? '—' }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Priority</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ ucfirst($notification->priority ?? 'regular') }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Cancellation Reason</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ $notification->reason ?? '—' }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-3 sm:col-span-2">
                <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Cancellation Date &amp; Time</div>
                <div class="font-semibold text-slate-800 mt-0.5">{{ optional($notification->cancelled_at)->format('l, F j, Y g:i A') ?? '—' }}</div>
            </div>
        </div>

        @if($notification->ticket)
            <div class="p-4 pt-0">
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-3 text-xs text-slate-600">
                    <div class="font-bold text-slate-800 mb-1">Related ticket record (preserved for audit)</div>
                    <div>Status: <strong>{{ $notification->ticket->status }}</strong> · Service: {{ $notification->ticket->service->name ?? '—' }} · Created: {{ optional($notification->ticket->created_at)->format('M d, Y g:i A') }}</div>
                    <a href="{{ route('staff.history.index', ['date' => optional($notification->ticket->created_at)->toDateString()]) }}" class="text-blue-600 underline">View in queue history →</a>
                </div>
            </div>
        @endif

        <div class="p-4 pt-0 flex flex-wrap gap-2">
            @if(!$notification->is_read)
                <form method="POST" action="{{ route('staff.notifications.read', $notification) }}">
                    @csrf
                    <button type="submit" class="btn-primary text-xs">Mark as read</button>
                </form>
            @endif
            <a href="{{ route('staff.notifications.index') }}" class="btn-secondary text-xs">Back to history</a>
        </div>
    </div>

</div>
@endsection
