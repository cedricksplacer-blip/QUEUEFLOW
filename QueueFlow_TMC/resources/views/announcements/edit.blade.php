@extends('layouts.admin')
@section('title','Edit Announcement')
@section('content')
<div class="max-w-3xl card p-6"><form method="POST" action="{{ route('admin.announcements.update',$announcement) }}" class="space-y-5">@csrf @method('PUT')
<div><label class="block text-sm font-semibold mb-1.5">Title</label><input class="input" name="title" value="{{ old('title',$announcement->title) }}" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Message</label><textarea class="input" name="body" rows="7" required>{{ old('body',$announcement->body) }}</textarea></div>
<div><label class="block text-sm font-semibold mb-1.5">Audience</label><select class="input" name="audience" required><option value="user" @selected(old('audience', $announcement->audience ?? 'both') === 'user')>User Only</option><option value="staff" @selected(old('audience', $announcement->audience ?? 'both') === 'staff')>Staff Only</option><option value="both" @selected(old('audience', $announcement->audience ?? 'both') === 'both')>User and Staff</option></select></div>
<x-toggle name="is_active" :checked="(bool) old('is_active',$announcement->is_active)" label="Visible to students" on="Visible on the student portal" off="Hidden from students" />
<div class="flex gap-2"><button class="btn-primary">Save Changes</button><a class="btn-secondary" href="{{ route('admin.announcements.index') }}">Cancel</a></div></form></div>
@endsection
