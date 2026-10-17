@extends('layouts.staff')

@section('title', 'Request Announcement')

@section('content')

<div class="staff-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC - Staff Announcements</p>
    <h2 class="text-xl font-bold mt-1">Request an Announcement</h2>
    <p class="text-xs mt-1" style="color:#d6efe4">Your request goes to the administrator for verification. It only goes live once accepted.</p>
</div>

<div class="card p-6">
    <form method="POST" action="{{ route('staff.announcements.store') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-semibold mb-1.5">Title</label>
            <input class="input" name="title" value="{{ old('title') }}" required>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Message</label>
            <textarea class="input" name="body" rows="7" required>{{ old('body') }}</textarea>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Audience</label>
            <select class="input" name="audience">
                <option value="user" @selected(old('audience') === 'user')>User Only</option>
                <option value="staff" @selected(old('audience') === 'staff')>Staff Only</option>
                <option value="both" @selected(old('audience', 'both') === 'both')>User and Staff</option>
            </select>
        </div>

        <div class="flex items-center gap-2 text-xs text-slate-500">
            <span class="badge badge-waiting">Pending review</span>
            Submitted from the <strong class="text-slate-700">{{ $office->name }}</strong> office — will be verified by the admin.
        </div>

        <div class="flex gap-2">
            <button class="btn-primary">Submit for Verification</button>
            <a class="btn-secondary" href="{{ route('staff.announcements.index') }}">Cancel</a>
        </div>
    </form>
</div>

@endsection