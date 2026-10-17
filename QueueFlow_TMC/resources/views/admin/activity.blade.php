@extends('layouts.admin')
@section('title','Queue Activity History')
@section('content')
<div class="card p-4 mb-5">
    <div class="text-sm font-bold text-slate-900">Queue Activity History</div>
    <p class="text-[11px] text-slate-500 mt-1">Every queue ticket from all offices and Registrar counters, latest first. Reads the live database, so new activity appears on refresh.</p>
</div>
<div class="flex flex-col justify-between gap-3 mb-5">
    <form method="GET" data-qf-auto-filter class="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3 flex-1 items-center">
        <input class="input" name="search" value="{{ request('search') }}" placeholder="Search queue, student, ID, purpose" autocomplete="off">
        <select class="input" name="office_id"><option value="">All offices</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(request('office_id') == $office->id)>{{ $office->name }}</option>@endforeach</select>
        <select class="input" name="course_id"><option value="">All courses</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected(request('course_id') == $course->id)>{{ $course->code }}</option>@endforeach</select>
        <select class="input" name="priority"><option value="">All priorities</option>@foreach(['regular','pwd'] as $priority)<option value="{{ $priority }}" @selected(request('priority')===$priority)>{{ strtoupper($priority) }}</option>@endforeach</select>
        <select class="input" name="status"><option value="">All statuses</option>@foreach(['waiting','serving','served','cancelled','no_show'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status === 'no_show' ? 'No-Show' : ucfirst($status) }}</option>@endforeach</select>
        <input class="input" type="date" name="date" value="{{ $date }}" title="Empty shows all dates">
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating…</span></span>
    </form>
    @if(request()->filled('search') || request()->filled('office_id') || request()->filled('course_id') || request()->filled('priority') || request()->filled('status') || request()->filled('date'))
        <div><a href="{{ route('admin.activity.index') }}" class="btn-secondary">Reset</a></div>
    @endif
</div>
@include('partials.update-banner')
<div class="card"><div class="table-wrap"><table class="admin-table"><thead><tr><th>Queue</th><th>Office / Counter</th><th>Course</th><th>Student</th><th>Priority</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>
@forelse($activities as $activity)<tr>
<td class="font-bold">{{ $activity->queue_number }}</td>
<td><div class="font-medium">{{ $activity->office->name ?? '—' }}</div><div class="text-[11px] text-slate-400">{{ $activity->office->code ?? "" }}</div></td>
<td>{{ $activity->course->code ?? "—" }}</td>
<td><div class="font-medium">{{ $activity->student->name ?? 'Walk-in' }}</div><div class="text-[11px] text-slate-400">{{ $activity->student->student_id ?? "" }}</div></td>
<td>@if($activity->isPwd())
    {{-- Every PWD row's badge is its own trigger, wired to that exact
         ticket's evidence route so each student opens their own picture. --}}
    <button type="button" class="js-pwd-proof badge badge-pwd qf-pwd-badge" title="{{ filled($activity->priority_evidence) ? 'View PWD verification proof' : 'No PWD proof picture available for this student.' }}" aria-label="View PWD verification proof for queue {{ $activity->queue_number }}" data-url="{{ filled($activity->priority_evidence) ? route('admin.queues.evidence', $activity) : '' }}" data-student="{{ $activity->student->name ?? 'Walk-in' }}" data-student-id="{{ $activity->student->student_id ?? '' }}" data-office="{{ $activity->office->name ?? '' }}" data-queue="{{ $activity->queue_number }}" data-purpose="{{ $activity->purpose ?? '' }}" data-status="{{ $activity->status }}" data-time="{{ optional($activity->created_at)->format('M d, Y h:i A') ?? '' }}">PWD</button>
@else<span class="text-slate-400">Regular</span>@endif</td>
<td><span class="badge badge-{{ $activity->status === 'no_show' ? 'cancelled' : $activity->status }}">{{ $activity->status === 'no_show' ? 'No-Show' : ucfirst($activity->status) }}</span></td>
<td class="text-slate-500 whitespace-nowrap">{{ $activity->created_at->format('M d, Y h:i A') }}</td>
</tr>@empty<tr><td colspan="7" class="text-center text-slate-500 py-10">No queue activity found.</td></tr>@endforelse
</tbody></table></div><div class="p-4">{{ $activities->links('vendor.pagination.queueflow') }}</div></div>
@include('partials.auto-filter')
@include('partials.live-refresh')
@include('partials.pwd-proof-modal')
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Banner-only freshness for the history table: filtered views never reload. */
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
