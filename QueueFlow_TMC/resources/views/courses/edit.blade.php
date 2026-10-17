@extends('layouts.admin')
@section('title','Edit Course')
@section('content')
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.courses.update',$course) }}" class="space-y-5">@csrf @method('PUT')
<div><label class="block text-sm font-semibold mb-1.5">Course Code</label><input class="input" name="code" value="{{ old('code',$course->code) }}" maxlength="20" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Course Name</label><input class="input" name="name" value="{{ old('name',$course->name) }}" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Office</label><select class="input" name="office_id"><option value="">None</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id',$course->office_id)==$office->id)>{{ $office->name }}</option>@endforeach</select></div>
<div><label class="block text-sm font-semibold mb-1.5">Assigned Staff</label><select class="input" name="staff_id"><option value="">Unassigned</option>@foreach($staff as $member)<option value="{{ $member->id }}" @selected(old('staff_id',$course->staff_id)==$member->id)>{{ $member->name }} — {{ $member->office->name ?? 'Unassigned' }}</option>@endforeach</select></div>
<x-toggle name="is_active" :checked="(bool) old('is_active',$course->is_active)" label="Course is active" on="Available in the student queue form" off="Hidden from the queue form" />
<div class="flex gap-2"><button class="btn-primary">Save Changes</button><a class="btn-secondary" href="{{ route('admin.courses.index') }}">Cancel</a></div></form></div>
@endsection