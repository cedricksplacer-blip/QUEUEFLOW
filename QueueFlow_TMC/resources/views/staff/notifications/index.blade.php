@extends('layouts.staff')

@section('title', 'Cancellation Alerts')

@section('content')
<div class="qf-dashboard w-full max-w-[1080px] mx-auto px-3 sm:px-4 min-w-0">

    <div class="qf-reveal mb-5">
        <h3 class="text-sm font-bold text-slate-900">Cancellation Alerts — {{ $office->name }}</h3>
        <p class="text-xs text-slate-500 mt-1">
            {{ $totalCount }} alert(s) · {{ $unreadCount }} unread. New cancellations appear here automatically.
        </p>
    </div>

    @include('partials.update-banner')
    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">
        <div class="p-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <div class="flex gap-2">
                <a href="{{ route('staff.notifications.index', ['filter' => 'all']) }}" class="btn-secondary text-xs {{ $filter !== 'unread' ? 'font-bold' : '' }}">All</a>
                <a href="{{ route('staff.notifications.index', ['filter' => 'unread']) }}" class="btn-secondary text-xs {{ $filter === 'unread' ? 'font-bold' : '' }}">Unread ({{ $unreadCount }})</a>
            </div>
            <form method="POST" action="{{ route('staff.notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn-secondary text-xs">Mark all as read</button>
            </form>
        </div>

        <div class="p-3">
            @forelse($notifications as $notification)
                <div class="rounded-xl border px-3 py-2.5 mb-2 {{ $notification->is_read ? 'border-slate-100 bg-white' : 'border-red-200 bg-red-50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-bold text-slate-900">{{ $notification->title }}</span>
                                @if(!$notification->is_read)<span class="badge badge-cancelled">new</span>@endif
                            </div>
                            <p class="text-xs text-slate-600 mt-1">{{ $notification->message }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-0.5 mt-2 text-[11px] text-slate-500">
                                <div><strong>Student:</strong> {{ $notification->student_name }} ({{ $notification->student_id_no ?? '—' }})</div>
                                <div><strong>Ticket:</strong> {{ $notification->queue_number }}</div>
                                <div><strong>Office:</strong> {{ $notification->office_name }}</div>
                                <div><strong>Purpose:</strong> {{ $notification->purpose ?? '—' }}</div>
                                <div><strong>Priority:</strong> {{ ucfirst($notification->priority ?? 'regular') }}</div>
                                <div><strong>Cancelled:</strong> {{ optional($notification->cancelled_at)->format('M d, Y g:i A') ?? '—' }}</div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5 flex-shrink-0">
                            <a href="{{ route('staff.notifications.show', $notification) }}" class="btn-secondary text-xs text-center">Details</a>
                            @if(!$notification->is_read)
                                <form method="POST" action="{{ route('staff.notifications.read', $notification) }}">
                                    @csrf
                                    <button type="submit" class="btn-primary text-xs w-full">Mark read</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500 py-6 text-center">
                    @if($filter === 'unread')
                        No unread cancellation alerts. You're all caught up.
                    @else
                        No cancellation alerts for {{ $office->name }} yet.
                    @endif
                </p>
            @endforelse
        </div>

    </div>

    <div class="card mt-5"><div class="p-4">{{ $notifications->links('vendor.pagination.queueflow') }}</div></div>

</div>
@include('partials.live-refresh')
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* New cancellation alerts raise a banner; read states and paging stay put. */
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
            url: '{{ route('staff.notifications.recent') }}',
            interval: 20000,
            onData: function (data) {
                var rows = data && Array.isArray(data.notifications) ? data.notifications : [];
                var top = 0;
                rows.forEach(function (n) {
                    var id = parseInt(n && n.id, 10) || 0;
                    if (id > top) { top = id; }
                });
                lastSeen = top;
                if (firstSeen === null) { firstSeen = top; armDismiss(); return; }
                armDismiss();
                if (top > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New cancellation alerts — refresh the view to see them.');
                }
            }
        });
    }
});
</script>
@endsection
