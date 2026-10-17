@extends('layouts.admin')
@section('title','New Announcement')
@section('content')
<div class="max-w-3xl card p-6"><form method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-5">@csrf
<div><label class="block text-sm font-semibold mb-1.5">Title</label><input class="input" name="title" value="{{ old('title') }}" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Message</label><textarea class="input" name="body" rows="7" required>{{ old('body') }}</textarea></div>
<div><label class="block text-sm font-semibold mb-1.5">Audience</label><select class="input" name="audience" required><option value="user" @selected(old('audience') === 'user')>User Only</option><option value="staff" @selected(old('audience') === 'staff')>Staff Only</option><option value="both" @selected(old('audience', 'both') === 'both')>User and Staff</option></select></div>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded" checked> Publish immediately</label>
<div class="flex gap-2"><button class="btn-primary">Create Announcement</button><a class="btn-secondary" href="{{ route('admin.announcements.index') }}">Cancel</a></div></form></div>
@endsection
