@extends('layouts.student')
@section('title','Queue Ticket')
@section('content')
<div class="student-hero p-6 text-center mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#bcd3f5">QueueFlow TMC</p>
    <h2 class="text-2xl font-extrabold mt-1 tracking-wide">{{ $ticket->queue_number }}</h2>
    <p class="text-xs mt-1" style="color:#d7e6fb">{{ $ticket->office->name }}</p>
</div>

<div class="card p-5">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
        <span class="text-xs uppercase tracking-wide text-slate-400 font-bold">Status</span>
        <div class="flex items-center gap-2">
            @if($ticket->isPwd())<span class="badge badge-pwd">PWD Priority</span>@endif
            <span class="badge badge-{{ $ticket->status }}">{{ $ticket->status }}</span>
        </div>
    </div>

    <dl class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Office</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->office->name }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Service</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->service->name ?? "—" }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Station</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->station->location ?? "—" }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Course</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->course->code ?? '—' }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Batch</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->deployment ? 'Batch #'.$ticket->deployment->batch_no : '—' }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Issued</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->created_at->format('g:i A') }}</dd></div>
        @if($ticket->called_at)
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Called</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->called_at->format('g:i A') }}</dd></div>
        @endif
        @if($ticket->served_at)
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Served</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->served_at->format('g:i A') }}</dd></div>
        @endif
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-400">Purpose</dt><dd class="font-bold text-slate-800 mt-0.5">{{ $ticket->purpose ?: '—' }}</dd></div>
    </dl>

    @if($ticket->isWaiting())
    <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-center">
        <div class="text-2xl font-extrabold text-blue-800">{{ $estimatedWaitMinutes }} min</div>
        <p class="text-xs text-blue-600 mt-1">Estimated waiting time. Please stay near the {{ $ticket->office->name }} counter.</p>
        <a href="{{ url()->current() }}" class="inline-block mt-3 text-xs font-semibold text-blue-600 hover:text-blue-800">↻ Refresh status</a>
    </div>
    @elseif($ticket->isServing())
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-center">
        <p class="text-sm font-bold text-amber-800">You are now being served.</p>
        <p class="text-xs text-amber-700 mt-1">Please proceed to the {{ $ticket->office->name }} counter.</p>
    </div>
    @elseif($ticket->isServed())
    <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center">
        <p class="text-sm font-bold text-emerald-800">You have been served. Thank you!</p>
        @if($ticket->servedByStaff)<p class="text-xs text-emerald-700 mt-1">Served by {{ $ticket->servedByStaff->name }}.</p>@endif
    </div>
    @else
    <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-center">
        <p class="text-sm font-bold text-red-700">This ticket is no longer active.</p>
    </div>
    @endif

    <div class="mt-5 flex gap-2">
        <a href="{{ route('student.queues.index') }}" class="btn-secondary flex-1">Back to My Queues</a>
        <a href="{{ route('student.queues.create') }}" class="btn-primary flex-1">Take Another Ticket</a>
    </div>
</div>
@endsection