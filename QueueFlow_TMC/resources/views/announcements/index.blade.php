@extends('layouts.admin')
@section('title','Announcements')
@section('content')
@php
    $statusBadges = [
        \App\Models\Announcement::STATUS_PENDING => ['badge-waiting', $statusTiles[\App\Models\Announcement::STATUS_PENDING]],
        \App\Models\Announcement::STATUS_PUBLISHED => ['badge-served', $statusTiles[\App\Models\Announcement::STATUS_PUBLISHED]],
        \App\Models\Announcement::STATUS_REJECTED => ['badge-cancelled', $statusTiles[\App\Models\Announcement::STATUS_REJECTED]],
    ];
    $tileStyles = [
        \App\Models\Announcement::STATUS_PUBLISHED => ['badge-served', 'border-emerald-200', 'text-emerald-700'],
        \App\Models\Announcement::STATUS_PENDING => ['badge-waiting', 'border-amber-200', 'text-amber-700'],
        \App\Models\Announcement::STATUS_REJECTED => ['badge-cancelled', 'border-rose-200', 'text-rose-700'],
    ];
    $officeValue = $office ?? 'all';
@endphp

{{-- Status tiles: counts for the selected office, click to filter --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
    <a href="{{ route('admin.announcements.index', ['office' => $officeValue]) }}"
       class="card p-4 border {{ $status === null ? 'border-blue-300 ring-1 ring-blue-200' : 'border-slate-100' }} hover:border-blue-300 transition">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">All statuses</div>
        <div class="text-2xl font-black mt-1 text-slate-800">{{ $counts['all'] }}</div>
    </a>
    @foreach($statusTiles as $key => $label)
        @php [$badgeClass, $borderClass, $textClass] = $tileStyles[$key]; @endphp
        <a href="{{ route('admin.announcements.index', ['office' => $officeValue, 'status' => $key]) }}"
           class="card p-4 border {{ $status === $key ? 'border-blue-300 ring-1 ring-blue-200' : $borderClass }} hover:border-blue-300 transition">
            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">{{ $label }}</div>
            <div class="text-2xl font-black mt-1 {{ $textClass }}">{{ $counts[$key] }}</div>
        </a>
    @endforeach
</div>

{{-- Filters (auto-applies on dropdown change; no Filter button needed) --}}
<div class="flex flex-col sm:flex-row gap-3 mb-5">
    <form method="GET" data-qf-auto-filter class="card p-3 flex flex-wrap gap-2 items-center">
        <select class="input" name="office">
            <option value="all" @selected($officeValue === 'all')>All offices</option>
            @foreach($offices as $officeId => $officeName)
                <option value="{{ $officeId }}" @selected($officeValue === (string) $officeId)>{{ $officeName }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statusTiles as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
            <option value="active" @selected($status === 'active')>Visible to students</option>
            <option value="inactive" @selected($status === 'inactive')>Hidden from students</option>
        </select>
        <select class="input" name="audience">
            <option value="">All audiences</option>
            @foreach($audiences as $key => $label)
                <option value="{{ $key }}" @selected(($audience ?? null) === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating…</span></span>
        @if($status !== null || $officeValue !== 'all' || ($audience ?? null) !== null)
            <a href="{{ route('admin.announcements.index') }}" class="btn-secondary">Reset</a>
        @endif
    </form>
    <a href="{{ route('admin.announcements.create') }}" class="btn-primary sm:ml-auto self-start">+ New Announcement</a>
</div>

<div class="card">
    <div class="table-wrap overflow-x-auto">
        <table class="admin-table min-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Posted By</th>
                    <th>From Office</th>
                    <th>Status</th>
                    <th>Audience</th>
                    <th>Student View</th>
                    <th>Created</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($announcements as $a)
                @php
                    [$statusBadgeClass, $statusBadgeText] = $statusBadges[$a->status]
                        ?? [$a->is_active ? 'badge-served' : 'badge-cancelled', $a->is_active ? 'Visible' : 'Hidden'];
                @endphp
                <tr>
                    <td class="min-w-0 max-w-[200px]">
                        <div class="font-semibold truncate" title="{{ $a->title }}">{{ $a->title }}</div>
                        <div class="text-xs text-slate-500 mt-1 truncate" title="{{ $a->body }}">{{ \Illuminate\Support\Str::limit($a->body,40) }}</div>
                        @if($a->status === \App\Models\Announcement::STATUS_PENDING && $a->submittedBy)
                            <div class="text-[10px] mt-1 text-amber-600 font-semibold truncate" title="Requested by {{ $a->submittedBy->name }}">Requested by {{ $a->submittedBy->name }}</div>
                        @endif
                    </td>
                    <td>{{ $a->author->name ?? 'System' }}</td>
                    <td>
                        @if($a->office)
                            <span class="text-xs font-semibold">{{ $a->office->name }}</span>
                            <span class="text-[10px] text-slate-400 ml-1">{{ $a->office->code }}</span>
                        @else
                            <span class="text-xs text-slate-400">Global</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $statusBadgeClass }}">{{ $statusBadgeText }}</span></td>
                    <td>
                        <span class="badge bg-slate-100 text-slate-600">
                            {{ $a->audienceLabel() }}
                        </span>
                    </td>
                    <td>
                        <span class="badge {{ $a->is_active ? 'badge-served' : 'bg-slate-100 text-slate-600' }}">
                            {{ $a->is_active ? 'Visible' : 'Hidden' }}
                        </span>
                    </td>
                    <td>{{ $a->created_at->format('M d, Y') }}</td>
                    <td class="text-right whitespace-nowrap">
                        <div class="flex justify-end items-center gap-1 flex-nowrap">
                            @if($a->status === \App\Models\Announcement::STATUS_PENDING)
                                <form method="POST" action="{{ route('admin.announcements.approve',$a) }}" class="inline-flex">@csrf<button class="btn-primary text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Accept</button></form>
                                <form method="POST" action="{{ route('admin.announcements.reject',$a) }}" class="inline-flex" onsubmit="return confirm('Reject this announcement request?')">@csrf<button class="btn-danger text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Reject</button></form>
                            @endif
                            <a class="btn-secondary text-[10px] px-1.5 h-6 inline-flex items-center whitespace-nowrap leading-none" href="{{ route('admin.announcements.edit',$a) }}">Edit</a>
                            <form method="POST" action="{{ route('admin.announcements.destroy',$a) }}" class="inline-flex" onsubmit="return confirm('Delete this announcement?')">@csrf @method('DELETE')<button class="btn-danger text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Delete</button></form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-slate-500 py-10">No announcements found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $announcements->links() }}</div>
</div>
@include('partials.auto-filter')
@endsection
