@extends('layouts.admin')
@section('title','Reports')
@section('content')
<form method="GET" data-qf-auto-filter class="card p-4 mb-5 grid grid-cols-1 sm:grid-cols-3 gap-3 items-end"><div><label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">From</label><input class="input" type="date" name="from" value="{{ $from }}"></div><div><label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">To</label><input class="input" type="date" name="to" value="{{ $to }}"></div><div class="flex items-center"><span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating...</span></span></div></form>
<div class="grid grid-cols-3 xl:grid-cols-6 gap-4 mb-5">@foreach($summary as $key=>$value)<div class="card p-5"><div class="text-xs uppercase tracking-wider text-slate-400 font-bold">{{ ucfirst($key) }}</div><div class="text-2xl font-bold mt-2">{{ $value }}</div></div>@endforeach</div>

<div class="grid xl:grid-cols-2 gap-5"><div class="card"><div class="p-5 border-b border-slate-100 font-bold">Tickets by Office</div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Office</th><th>Total</th><th>Served</th><th>Cancelled</th><th>No-Show</th><th>Completion</th></tr></thead><tbody>@forelse($byOffice as $office)<tr><td class="font-semibold">{{ $office->name }}</td><td>{{ $office->total_tickets }}</td><td>{{ $office->served_tickets }}</td><td>{{ $office->cancelled_tickets }}</td><td>{{ $office->no_show_tickets ?? 0 }}</td><td>{{ $office->total_tickets ? round($office->served_tickets/$office->total_tickets*100) : 0 }}%</td></tr>@empty<tr><td colspan="6" class="text-center text-slate-500 py-8">No data.</td></tr>@endforelse</tbody></table></div></div><div class="card"><div class="p-5 border-b border-slate-100 font-bold">Daily Volume</div><div class="p-5 space-y-3">@forelse($dailyVolume as $row)<div class="flex items-center justify-between border-b border-slate-100 pb-3"><span>{{ \Carbon\Carbon::parse($row->day)->format('M d, Y') }}</span><strong>{{ $row->total }}</strong></div>@empty<div class="text-slate-500">No data for this period.</div>@endforelse</div></div></div>
<div class="card mt-5"><div class="p-5 border-b border-slate-100"><div class="font-bold">Station Daily Capacity</div><p class="text-xs text-slate-500 mt-1">Every station is at {{ \App\Models\OfficeStation::CAMPUS_LOCATION }}. Select an office to view its station details.</p></div><style>
        tr.qf-cap-group td { background: #f8fafc; padding: 0 !important; }
        .qf-cap-toggle { width: 100%; display: flex; align-items: center; gap: 8px; padding: 9px 11px; background: transparent; border: 0; cursor: pointer; font-size: 11px; color: #334155; text-align: left; }
        .qf-cap-toggle:hover { background: #f1f5f9; }
        .qf-cap-toggle:focus-visible { outline: 2px solid #2563eb; outline-offset: -2px; }
        .qf-cap-toggle.is-selected { background: #eff6ff; }
        .qf-cap-caret { margin-left: auto; flex-shrink: 0; color: #94a3b8; transition: transform .25s ease; }
        .qf-cap-toggle[aria-expanded="true"] .qf-cap-caret { transform: rotate(180deg); }
        tr[data-cap-rows][hidden] { display: none; }
        html.dark tr.qf-cap-group td { background: #1e293b !important; }
        html.dark .qf-cap-toggle { color: #e2e8f0; }
        html.dark .qf-cap-toggle:hover { background: #0f172a !important; }
        html.dark .qf-cap-toggle.is-selected { background: #172554 !important; }
        @media (prefers-reduced-motion: reduce) { .qf-cap-caret { transition: none; } }
    </style><div class="table-wrap"><table class="admin-table"><thead><tr><th>Office</th><th>Station</th><th>Location</th><th>Target / Day</th><th>Tickets</th><th>Served</th><th>Capacity</th></tr></thead><tbody>@php($stationsByOffice = $byStation->groupBy('office_id'))@if($stationsByOffice->isNotEmpty())@foreach($stationsByOffice as $officeId => $stations)<tr class="qf-cap-group"><td colspan="7"><button type="button" class="qf-cap-toggle js-cap-toggle" aria-expanded="false" data-cap-office="{{ $officeId }}"><span class="font-semibold">{{ $stations->first()->office->name ?? 'Office' }}</span><svg class="qf-cap-caret" viewBox="0 0 20 20" fill="currentColor" width="14" height="14" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button></td></tr>@foreach($stations as $station)<tr data-cap-rows="{{ $officeId }}" hidden><td>{{ $station->office->name }}</td><td class="font-semibold">{{ $station->name }}</td><td>@if(blank($station->location))<span class="text-slate-400">Not set</span>@elseif($station->location === \App\Models\OfficeStation::CAMPUS_LOCATION)<span class="text-slate-500">Main campus</span>@else{{ $station->location }}@endif</td><td>{{ $station->min_daily_students }}–{{ $station->target_daily_students }}</td><td>{{ $station->total_tickets }}</td><td>{{ $station->served_tickets }}</td><td>{{ $stations->sum('max_daily_slots') }}</td></tr>@endforeach
@endforeach
@else<tr><td colspan="7" class="text-center text-slate-500 py-8">No station data.</td></tr>@endif</tbody></table></div></div>
<script>
document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('.js-cap-toggle') : null;
    if (!btn) { return; }
    var id = btn.getAttribute('data-cap-office');
    var willOpen = btn.getAttribute('aria-expanded') !== 'true';
    document.querySelectorAll('.js-cap-toggle').forEach(function (other) {
        var otherId = other.getAttribute('data-cap-office');
        var open = other === btn && willOpen;
        other.setAttribute('aria-expanded', open ? 'true' : 'false');
        other.classList.toggle('is-selected', open);
        document.querySelectorAll('[data-cap-rows="' + otherId + '"]').forEach(function (row) {
            row.hidden = !open;
        });
    });
});
</script>
@include('partials.auto-filter')
@endsection
