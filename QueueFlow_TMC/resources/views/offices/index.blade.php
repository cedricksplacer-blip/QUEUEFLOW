@extends('layouts.admin')
@section('title','Offices')
@section('content')
@php
    $deploymentBadges = [
        'open' => ['badge-serving', 'OPEN — accepting tickets'],
        'full' => ['badge-waiting', 'FULL — 100 slots reached'],
        'closed' => ['badge-cancelled', 'CLOSED'],
        'deployed' => ['badge-serving', 'DEPLOYED — processing'],
        'completed' => ['badge-served', 'COMPLETED'],
    ];
@endphp
<div class="flex justify-between items-center mb-5"><div><p class="text-slate-500 text-sm">Manage service locations, queue prefixes, schedule, and 100-slot batches.</p></div><a href="{{ route('admin.offices.create') }}" class="btn-primary">+ Add Office</a></div>
<div class="grid md:grid-cols-2 xl:grid-cols-2 gap-4">@forelse($offices as $office)
@php
    $deployment = $office->activeDeployment;
    $status = $deployment ? $deployment->status : null;
    $limit = $office->slotLimit();
    $used = $deployment ? $deployment->submittedCount() : 0;
    $pct = $limit ? round($used / $limit * 100) : 0;
    [$badgeClass, $badgeText] = $deploymentBadges[$status] ?? ['bg-slate-100 text-slate-600','No batch yet'];
@endphp
<div class="card p-5">
    <div class="flex justify-between gap-3">
        <div><h3 class="font-bold text-lg">{{ $office->name }}</h3><div class="text-xs font-bold text-blue-600 mt-1">{{ $office->code }}</div></div>
        <form method="POST" action="{{ route('admin.offices.toggle-active',$office) }}" onsubmit="return confirm('{{ $office->is_active?'Deactivate':'Activate' }} {{ $office->name }}?')">@csrf
            <button class="btn-{{ $office->is_active?'danger':'primary' }}" style="height:24px;min-height:24px;padding:0 8px;font-size:10px">
                {{ $office->is_active?'Turn Off':'Turn On' }}
            </button>
        </form>
    </div>
    <p class="text-sm text-slate-500 mt-3 min-h-6">{{ $office->description ?: 'No description provided.' }}</p>
    @if($office->office_type || $office->location)
        <p class="text-xs text-slate-500 mt-1">{{ implode(' · ', array_filter([$office->office_type, $office->location])) }}</p>
    @endif

    <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Schedule</div><div class="font-bold text-sm mt-1">{{ $office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : '—' }} – {{ $office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : '—' }}</div></div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Max Slots / Batch</div><div class="font-bold text-sm mt-1">{{ $limit }}</div></div>
    </div>

    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-slate-500">Batch #{{ $deployment->batch_no ?? '—' }}</span>
            @if($status)<span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>@else<span class="badge bg-slate-100 text-slate-600">No batch yet</span>@endif
        </div>
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1"><span>{{ $used }} / {{ $limit }} slots used</span><span>@if($deployment && $deployment->servedCount() > 0) {{ $deployment->servedCount() }} served @endif</span></div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-amber-500' : 'bg-blue-500' }}" style="width:{{ $pct }}%"></div></div>
    </div>

    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-slate-500">Stations &amp; Daily Capacity</span>
            @if(in_array($office->code, \App\Models\Office::OPERATIONAL_CODES, true))<a href="{{ route('admin.offices.stations.create',$office) }}" class="text-[11px] font-bold text-blue-600 hover:underline">+ Add Station</a>@endif
        </div>
        <div class="space-y-2">
        @forelse($office->stations as $station)
            <div class="flex items-center justify-between gap-3 text-xs">
                <div>
                    <div class="font-semibold text-slate-700">{{ $station->name }}</div>
                    <div class="text-slate-400">{{ $station->location ?: 'No location set' }}</div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="badge bg-blue-50 text-blue-700">{{ $station->min_daily_students }}–{{ $station->target_daily_students }}/day · {{ $station->max_daily_slots }} slots</span>
                    <a href="{{ route('admin.offices.stations.edit',[$office,$station]) }}" class="text-[11px] font-bold text-blue-600 hover:underline">Edit</a>
                    <form method="POST" action="{{ route('admin.offices.stations.destroy',[$office,$station]) }}" onsubmit="return confirm('Remove this station?')">@csrf @method('DELETE')<button class="text-[11px] font-bold text-red-600 hover:underline">Delete</button></form>
                </div>
            </div>
        @empty
            <div class="text-[11px] text-slate-400">No stations yet.</div>
        @endforelse
        </div>
    </div>
    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="text-xs font-semibold text-slate-500 mb-2">Services</div>
        <div class="flex flex-wrap gap-1.5">@foreach($office->services as $service)<span class="badge bg-slate-100 text-slate-600">{{ $service->name }}</span>@endforeach</div>
    </div>

    <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-slate-500">Staff</div><div class="font-bold text-lg">{{ $office->staff->count() }}</div>
            @forelse($office->staff as $member)
                <div class="flex items-center gap-2 mt-1.5">
                    <span class="flex h-6 w-6 items-center justify-center overflow-hidden rounded-md text-[10px] font-bold text-white" style="background:linear-gradient(135deg,#1E6A52,#16A37A)"><x-avatar :user="$member" /></span>
                    <span class="text-xs font-semibold text-slate-700">{{ $member->name }}</span>
                </div>
            @empty
                <div class="text-[11px] text-slate-400 mt-1.5">No staff assigned</div>
            @endforelse
        </div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Tickets</div><div class="font-bold text-lg">{{ $office->queue_tickets_count }}</div></div>
    </div>

    <div class="flex flex-wrap gap-2 mt-4">
        @if($status === 'open' || $status === 'full')
        <form method="POST" action="{{ route('admin.offices.close-deployment',$office) }}" onsubmit="return confirm('Are you sure you want to reset the batch number? The current batch will be closed and sent to history, and a new batch will start at #1.')">@csrf<button class="btn-secondary text-xs">Close & Reset Batch</button></form>
        @endif
        @if($status === 'deployed')
        <form method="POST" action="{{ route('admin.offices.complete-deployment',$office) }}" onsubmit="return confirm('Mark batch as completed and open a new 100-slot batch?')">@csrf<button class="btn-secondary text-xs">Complete & Start New Batch</button></form>
        @endif
    </div>

    <div class="flex gap-2 mt-4">
        <a class="btn-secondary flex-1" href="{{ route('admin.offices.edit',$office) }}">Settings</a>
        <form class="flex-1" method="POST" action="{{ route('admin.offices.destroy',$office) }}" onsubmit="return confirm('Delete this office?')">@csrf @method('DELETE')<button class="btn-danger w-full">Delete</button></form>
    </div>
</div>@empty<div class="card p-10 text-center text-slate-500 md:col-span-2 xl:col-span-2">No offices found.</div>@endforelse</div><div class="card mt-5"><div class="p-4">{{ $offices->links('vendor.pagination.queueflow') }}</div></div>
@endsection