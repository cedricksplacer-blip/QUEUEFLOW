@extends('layouts.admin')

@section('title', 'Cancellation Alerts')

@section('content')
<div class="card p-4 mb-4">
    <h3 class="text-sm font-bold">Cancellation Alerts — all offices</h3>
    <p class="text-xs text-slate-500 mt-1">Every student cancellation across the campus, newest first.</p>
</div>

<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.notifications.index') }}" data-qf-auto-filter class="flex flex-wrap gap-2 items-end">
        <div>
            <label class="text-xs font-semibold">Office</label>
            <select name="office_id" class="input text-xs">
                <option value="">All offices</option>
                @foreach($offices as $office)
                    <option value="{{ $office->id }}" {{ (int) $officeId === $office->id ? 'selected' : '' }}>{{ $office->name }} ({{ $office->code }})</option>
                @endforeach
            </select>
        </div>
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating...</span></span>
    </form>
</div>

@include('partials.update-banner')
<div class="card p-4">
    @forelse($notifications as $notification)
        <div class="rounded-xl border px-3 py-2.5 mb-2 {{ $notification->is_read ? 'border-slate-100 bg-white' : 'border-red-200 bg-red-50' }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-xs font-bold">{{ $notification->title }}</span>
                        <span class="badge badge-cancelled">{{ $notification->office_code }}</span>
                        @if(!$notification->is_read)<span class="badge badge-cancelled">new</span>@endif
                    </div>
                    <p class="text-xs text-slate-600 mt-1">{{ $notification->message }}</p>
                    <div class="text-[11px] text-slate-500 mt-1">
                        {{ $notification->student_name }} ({{ $notification->student_id_no ?? '—' }}) · {{ $notification->queue_number }} · {{ $notification->purpose ?? '—' }} · {{ optional($notification->cancelled_at)->format('M d, Y g:i A') }}
                    </div>
                </div>
                <a href="{{ route('admin.notifications.show', $notification) }}" class="btn-secondary text-xs flex-shrink-0">Details</a>
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500 py-6 text-center">No cancellation alerts yet.</p>
    @endforelse

    @if($notifications->hasPages())
        <div class="mt-3">{{ $notifications->links('vendor.pagination.queueflow') }}</div>
    @endif
</div>
@include('partials.auto-filter')
@include('partials.live-refresh')
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* New cancellation alerts surface as a banner; the list never reloads itself. */
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
            interval: 20000,
            onData: function (data) {
                lastSeen = data && data.latest_notification_id ? data.latest_notification_id : 0;
                if (firstSeen === null) { firstSeen = lastSeen; armDismiss(); return; }
                armDismiss();
                if (lastSeen > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New cancellation alerts — refresh the view to see them.');
                }
            }
        });
    }
});
</script>
@endsection
