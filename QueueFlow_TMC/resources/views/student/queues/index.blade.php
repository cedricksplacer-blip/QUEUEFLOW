@extends('layouts.student')
@section('title','My Queues')
@section('content')
<div class="student-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#bcd3f5">QueueFlow TMC</p>
    <h2 class="text-xl font-bold mt-1">Good day, {{ auth()->user()->name }}!</h2>
    <p class="text-xs mt-1" style="color:#d7e6fb">Track your queue tickets and estimated waiting time below.</p>
    <a href="{{ route('student.queues.create') }}" class="inline-flex mt-4 px-4 py-2 rounded-xl text-xs font-bold text-blue-900" style="background:#FFB300">Take a Ticket</a>
</div>

@forelse($tickets as $ticket)
<a href="{{ route('student.queues.show',$ticket) }}" class="card p-4 flex items-center justify-between gap-3 mb-3" style="text-decoration:none;">
    <div>
        <div class="flex items-center gap-2">
            <span class="text-lg font-extrabold text-blue-800">{{ $ticket->queue_number }}</span>
            @if($ticket->isPwd())<span class="badge badge-pwd">PWD</span>@endif
            <span class="badge badge-{{ $ticket->status }}">{{ $ticket->status }}</span>
        </div>
        <div class="text-xs text-slate-500 mt-1">
            {{ $ticket->office->name }}@if($ticket->course) <span class="font-semibold text-slate-700">· {{ $ticket->course->code }}</span>@endif
            @if($ticket->deployment)<span class="text-slate-400"> · Batch #{{ $ticket->deployment->batch_no }}</span>@endif
            · {{ $ticket->created_at->format('M d, g:i A') }}
        </div>
    </div>
    <span class="text-xs font-semibold text-blue-600">View →</span>
</a>
@empty
<div class="card p-8 text-center">
    <div class="text-3xl mb-2">🎟️</div>
    <p class="text-sm font-semibold text-slate-700">You have no queue tickets yet.</p>
    <p class="text-xs text-slate-500 mt-1">Select an office below to receive your queue number.</p>
    <a href="{{ route('student.queues.create') }}" class="btn-primary mt-4">Take a Ticket</a>
</div>
@endforelse
@endsection