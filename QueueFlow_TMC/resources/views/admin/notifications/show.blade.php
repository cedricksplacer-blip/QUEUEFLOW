@extends('layouts.admin')

@section('title', 'Cancellation Detail')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.notifications.index') }}" class="text-xs font-semibold text-blue-600">← Back to cancellation alerts</a>
</div>

<div class="card p-4">
    <h3 class="text-sm font-bold">{{ $notification->title }}</h3>
    <p class="text-xs text-slate-500 mt-1">{{ $notification->message }}</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4 text-xs">
        <div><strong>Student Name:</strong> {{ $notification->student_name ?? '—' }}</div>
        <div><strong>Student ID:</strong> {{ $notification->student_id_no ?? '—' }}</div>
        <div><strong>Ticket Number:</strong> {{ $notification->queue_number ?? '—' }}</div>
        <div><strong>Office:</strong> {{ $notification->office_name }} ({{ $notification->office_code }})</div>
        <div><strong>Purpose of Visit:</strong> {{ $notification->purpose ?? '—' }}</div>
        <div><strong>Priority:</strong> {{ ucfirst($notification->priority ?? 'regular') }}</div>
        <div class="sm:col-span-2"><strong>Cancellation Date &amp; Time:</strong> {{ optional($notification->cancelled_at)->format('l, F j, Y g:i A') ?? '—' }}</div>
    </div>

    @if(!$notification->is_read)
        <form method="POST" action="{{ route('admin.notifications.read', $notification) }}" class="mt-4">
            @csrf
            <button type="submit" class="btn-primary text-xs">Mark as read</button>
        </form>
    @endif
</div>
@endsection
