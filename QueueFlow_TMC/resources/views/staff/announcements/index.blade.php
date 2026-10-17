@extends('layouts.staff')

@section('title', 'Announcements')

@section('content')

<div class="staff-hero p-6 mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC - Staff</p>
            <p class="text-xs mt-1" style="color:#d6efe4">Official notices for all offices. You can also submit your own - it needs admin approval before it goes live.</p>
        </div>
        <a href="{{ route('staff.announcements.create') }}" class="btn-secondary text-xs" style="background:#fff;color:#123B2E">+ Request Announcement</a>
    </div>
</div>

@forelse($announcements as $notice)
    <div class="card p-5 mb-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-800"><a href="{{ route('staff.announcements.show', $notice) }}" class="hover:underline">{{ $notice->title }}</a></h3>
                <div class="text-[11px] text-slate-400 mt-1">
                    Posted by {{ $notice->author->name ?? 'Administrator' }}
                    @if($notice->office) - {{ $notice->office->name }}@endif
                    - {{ $notice->created_at->format('M j, Y - g:i A') }}
                    · {{ $notice->audienceLabel() }}
                </div>
            </div>
            <span class="flex-shrink-0 inline-flex items-center gap-1">
                <span class="badge badge-served">active</span>
                @if(in_array((int) $notice->id, $readIds ?? [], true))
                    <span class="badge bg-slate-100 text-slate-600">Read</span>
                @else
                    <span class="badge badge-waiting">Unread</span>
                @endif
            </span>
        </div>
        <div class="text-sm text-slate-600 mt-3 leading-relaxed whitespace-pre-line">{{ $notice->body }}</div>
    </div>
@empty
    <div class="card p-8 text-center">
        <div class="text-3xl mb-2">📢</div>
        <p class="text-sm font-semibold text-slate-700">No active announcements.</p>
        <p class="text-xs text-slate-500 mt-1">Published announcements will appear here.</p>
    </div>
@endforelse

@if($myAnnouncements->isNotEmpty())
    <div class="mt-8 mb-3">
        <p class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">My Announcement Requests</p>
    </div>

    @foreach($myAnnouncements as $request)
        @php
            $requestBadge = [
                'pending' => ['badge-waiting', 'Pending admin review'],
                'published' => ['badge-served', 'Accepted - live'],
                'rejected' => ['badge-cancelled', 'Rejected'],
            ][$request->status] ?? ['bg-slate-100 text-slate-600', ucfirst($request->status)];
        @endphp
        <div class="card p-5 mb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-bold text-slate-800">{{ $request->title }}</h3>
                    <div class="text-[11px] text-slate-400 mt-1">
                        Submitted {{ $request->created_at->format('M j, Y - g:i A') }}
                        @if($request->status === 'published' && $request->reviewedBy)
                            - Accepted by {{ $request->reviewedBy->name }}
                        @elseif($request->status === 'rejected' && $request->reviewedBy)
                            - Reviewed by {{ $request->reviewedBy->name }}
                        @endif
                    </div>
                </div>
                <span class="badge {{ $requestBadge[0] }} flex-shrink-0">{{ $requestBadge[1] }}</span>
            </div>
            <div class="text-sm text-slate-600 mt-3 leading-relaxed whitespace-pre-line">{{ $request->body }}</div>
            @if($request->status === 'rejected')
                <div class="mt-3">
                    <a href="{{ route('staff.announcements.create') }}" class="btn-secondary text-xs">Submit New Request</a>
                </div>
            @endif
        </div>
    @endforeach
@endif

@endsection
