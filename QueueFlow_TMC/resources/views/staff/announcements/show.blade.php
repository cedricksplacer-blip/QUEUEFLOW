@extends('layouts.staff')

@section('title', 'Announcement')

@section('content')

<div class="card p-6 mb-4">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-base font-bold text-slate-800">{{ $announcement->title }}</h2>
            <div class="text-[11px] text-slate-400 mt-1">
                Posted by {{ $announcement->author->name ?? 'Administrator' }}
                @if($announcement->office) - {{ $announcement->office->name }}@endif
                - {{ $announcement->created_at->format('M j, Y - g:i A') }}
                · {{ $announcement->audienceLabel() }}
            </div>
        </div>
        <span class="badge {{ $isRead ? 'bg-slate-100 text-slate-600' : 'badge-served' }} flex-shrink-0">{{ $isRead ? 'Read' : 'Unread' }}</span>
    </div>
    <div class="text-sm text-slate-600 mt-4 leading-relaxed whitespace-pre-line">{{ $announcement->body }}</div>
</div>

<a href="{{ route('staff.announcements.index') }}" class="btn-secondary text-xs">Back to Announcements</a>

@endsection
