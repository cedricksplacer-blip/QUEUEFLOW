@extends('layouts.staff')

@section('title', 'My Office')

@section('content')

<div class="staff-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC · Staff Office Profile</p>
    <h2 class="text-xl font-bold mt-1" style="color:#fff !important">{{ $office->name }}</h2>
    <p class="text-xs mt-1" style="color:#d6efe4">
        Code {{ $office->code }}
        @if($office->description) · {{ $office->description }}@endif
    </p>
    <p class="text-xs mt-2" style="color:#d6efe4">
        @if($office->activeDeployment)
            Batch #{{ $office->activeDeployment->batch_no }} ·
            {{ strtoupper($office->activeDeployment->status) }} ·
            {{ $office->activeDeployment->submittedCount() }} submitted ·
            {{ $office->activeDeployment->servedCount() }} served
        @else
            No active batch right now.
        @endif
    </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Office Setup</span>
        <div class="mt-3 space-y-3 text-xs">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Status</span>
                @if($office->is_active)
                    <span class="badge badge-served">active</span>
                @else
                    <span class="badge badge-cancelled">inactive</span>
                @endif
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-slate-500">Online Status</span>
                <div class="flex items-center gap-2">
                    <span class="badge {{ $office->isOnline() ? 'badge-online' : 'badge-offline' }}">
                        {{ strtoupper($office->status ?: \App\Models\Office::STATUS_OFFLINE) }}
                    </span>
                </div>
            </div>
            <p class="text-[10px] text-slate-400">Managed automatically — online while assigned staff are signed in.</p>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Acceptance Window</span>
                <span class="font-semibold text-slate-700">
                    {{ $office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : 'N/A' }} –
                    {{ $office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : 'N/A' }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Slot Limit per Batch</span>
                <span class="font-semibold text-slate-700">{{ $office->slotLimit() }}</span>
            </div>
        </div>
    </div>

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Staff Members</span>
        <div class="mt-3 space-y-2">
            @forelse($office->staff as $member)
                <div class="flex items-center gap-2 text-xs">
                    <span class="badge badge-served">{{ strtoupper(substr($member->name, 0, 1)) }}</span>
                    <div>
                        <div class="font-semibold text-slate-700">{{ $member->name }}</div>
                        <div class="text-[10px] text-slate-400">{{ $member->email }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No active staff assigned.</p>
            @endforelse
        </div>
    </div>

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Assignments</span>
        <div class="mt-3">
            <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mb-1">Courses</div>
            @forelse($office->courses as $course)
                <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100 last:border-0">
                    <span class="font-bold text-blue-800">{{ $course->code }}</span>
                    <span class="text-slate-500">{{ $course->assignedStaff->name ?? 'Unassigned' }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-500">No courses assigned to this office.</p>
            @endforelse

            <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mb-1 mt-4">Services</div>
            @forelse($office->services as $service)
                <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100 last:border-0">
                    <span class="font-semibold text-slate-700">{{ $service->name }}</span>
                    @if($service->is_active)
                        <span class="badge badge-served">active</span>
                    @else
                        <span class="badge badge-cancelled">inactive</span>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">No services configured.</p>
            @endforelse
        </div>
    </div>
</div>

<div class="card p-5">
    <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Stations</span>
    @if($office->stations->count())
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach($office->stations as $station)
                <span class="badge {{ $station->is_active ? 'badge-serving' : 'badge-cancelled' }}">{{ $station->name }}</span>
            @endforeach
        </div>
    @else
        <p class="text-sm text-slate-500 mt-3">No stations configured for this office.</p>
    @endif
</div>

<div class="card p-5 mt-5">
    <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Batch Management</span>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500">
            @if($office->activeDeployment)
                Currently on <strong class="text-slate-700">Batch #{{ $office->activeDeployment->batch_no }}</strong>
                ({{ strtoupper($office->activeDeployment->status) }}).
            @else
                No active batch right now.
            @endif
            <span class="block text-[10px] text-slate-400 mt-1">Reset to Batch 1 when closing or setting this office offline. History and tickets are kept.</span>
        </div>
        <div class="flex flex-wrap gap-2">
            @include('staff.partials.reset-batch-number')
        </div>
    </div>
</div>

<p class="text-[11px] text-slate-400 mt-4">Office setup, staff, courses, services and stations are managed by the administrator and reflected here automatically.</p>

@endsection