@extends('layouts.admin')
@section('title','Queue Management')
@section('content')
<div class="flex flex-col xl:flex-row xl:items-end justify-between gap-4 mb-5">
    <form method="GET" data-qf-auto-filter class="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3 flex-1 items-center">
        <input class="input" name="search" value="{{ request('search') }}" placeholder="Search queue, student, purpose" autocomplete="off">
        <select class="input" name="office_id"><option value="">All offices</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(request('office_id') == $office->id)>{{ $office->name }}</option>@endforeach</select>
        <select class="input" name="course_id"><option value="">All courses</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected(request('course_id') == $course->id)>{{ $course->code }}</option>@endforeach</select>
        <select class="input" name="priority"><option value="">All priorities</option>@foreach(['regular','pwd'] as $priority)<option value="{{ $priority }}" @selected(request('priority')===$priority)>{{ strtoupper($priority) }}</option>@endforeach</select>
        <select class="input" name="status"><option value="">All statuses</option>@foreach(['waiting','serving','served','cancelled','no_show'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status === 'no_show' ? 'No-Show' : ucfirst($status) }}</option>@endforeach</select>
        <input class="input" type="date" name="date" value="{{ $date }}">
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating…</span></span>
    </form>
    <a href="{{ route('admin.queues.create') }}" class="btn-primary">+ Generate Queue</a>
</div>
@include('partials.update-banner')
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
@foreach($offices as $office)
<div class="card p-4"><div class="flex justify-between gap-2"><div><div class="font-semibold">{{ $office->name }}</div><div class="text-xs text-slate-500">{{ $office->code }}</div></div><form method="POST" action="{{ route('admin.queues.call-next',$office) }}">@csrf<button class="btn-secondary text-xs">Call Next</button></form></div></div>
@endforeach
</div>
<div class="card"><div class="table-wrap"><table class="admin-table"><thead><tr><th>Queue</th><th>Office</th><th>Service / Station</th><th>Course</th><th>Student</th><th>Priority</th><th>Purpose</th><th>Status</th><th>Served By</th><th>Time</th><th></th></tr></thead><tbody>
@forelse($tickets as $ticket)<tr>
<td class="font-bold">{{ $ticket->queue_number }}</td>
<td>{{ $ticket->office->name }}</td>
<td><div class="font-medium">{{ $ticket->service->name ?? "—" }}</div><div class="text-[11px] text-slate-400">{{ $ticket->station->location ?? "" }}</div></td><td>{{ $ticket->course->code ?? "—" }}</td>
<td>{{ $ticket->student->name ?? 'Walk-in' }}</td>
<td>@if($ticket->isPwd())<button type="button" class="js-pwd-proof badge badge-pwd qf-pwd-badge" title="{{ filled($ticket->priority_evidence) ? 'View PWD verification proof' : 'No PWD proof picture available for this student.' }}" aria-label="View PWD verification proof for queue {{ $ticket->queue_number }}" data-url="{{ filled($ticket->priority_evidence) ? route('admin.queues.evidence',$ticket) : '' }}" data-student="{{ $ticket->student->name ?? 'Walk-in' }}" data-student-id="{{ $ticket->student->student_id ?? '' }}" data-office="{{ $ticket->office->name ?? '' }}" data-queue="{{ $ticket->queue_number }}" data-purpose="{{ $ticket->purpose ?? '' }}" data-status="{{ $ticket->status }}" data-time="{{ optional($ticket->created_at)->format('M d, Y h:i A') ?? '' }}">PWD</button>@else<span class="text-slate-400">—</span>@endif</td>
<td>{{ $ticket->purpose ?? '—' }}</td>
<td><span class="badge badge-{{ $ticket->status === 'no_show' ? 'cancelled' : $ticket->status }}">{{ $ticket->status === 'no_show' ? 'No-Show' : $ticket->status }}</span>@if($ticket->status === 'no_show' && $ticket->no_show_at)<div class="text-[10px] text-slate-400 mt-0.5">{{ optional($ticket->no_show_at)->format('M d, h:i A') }}</div>@endif</td>
<td>{{ $ticket->servedByStaff->name ?? '—' }}</td>
<td class="text-slate-500">{{ $ticket->created_at->format('h:i A') }}</td>
<td><div class="flex gap-1 justify-end">@foreach(['waiting','serving','served','cancelled','no_show'] as $status)<form method="POST" action="{{ route('admin.queues.status',$ticket) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $status }}"><button class="text-xs px-2 py-1 rounded border border-slate-200 hover:bg-slate-50" title="{{ $status === 'no_show' ? 'No-Show' : ucfirst($status) }}">{{ $status === 'no_show' ? 'NS' : strtoupper(substr($status,0,1)) }}</button></form>@endforeach<form method="POST" action="{{ route('admin.queues.destroy',$ticket) }}" onsubmit="return confirm('Delete this queue ticket?')">@csrf @method('DELETE')<button class="text-xs px-2 py-1 rounded border border-red-200 text-red-600">×</button></form></div></td></tr>@empty<tr><td colspan="11" class="text-center text-slate-500 py-10">No queue tickets found.</td></tr>@endforelse
</tbody></table></div><div class="p-4">{{ $tickets->links() }}</div></div>
@include('partials.pwd-proof-modal')
@include('partials.auto-filter')
@include('partials.live-refresh')
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* New queue activity shows a banner only — filters, search and
       pagination are never disturbed by an automatic reload. */
    var firstSeen = null;
    var lastSeen = 0;
    function armDismiss() {
        var dismiss = document.getElementById('qfStaleDismiss');
        if (dismiss && !dismiss.dataset.qfPingWired) {
            dismiss.dataset.qfPingWired = '1';
            dismiss.addEventListener('click', function () { firstSeen = lastSeen; });
        }
    }
    if (window.QfLive) {
        window.QfLive.poll({
            url: '{{ route('admin.queue-ping') }}',
            params: { office_id: '{{ request('office_id') }}' },
            interval: 20000,
            onData: function (data) {
                lastSeen = data && data.latest_ticket_id ? data.latest_ticket_id : 0;
                if (firstSeen === null) { firstSeen = lastSeen; armDismiss(); return; }
                armDismiss();
                if (lastSeen > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New queue activity — refresh the view to see it.');
                }
            }
        });
    }
});
</script>
@endsection