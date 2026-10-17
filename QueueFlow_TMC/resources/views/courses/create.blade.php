@extends('layouts.admin')
@section('title','Add Course')
@section('content')
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.courses.store') }}" class="space-y-5">@csrf
<div><label class="block text-sm font-semibold mb-1.5">Course Code</label><input class="input" name="code" value="{{ old('code') }}" maxlength="20" required placeholder="BSIT"><p class="text-xs text-slate-500 mt-1">Short code shown on tickets, e.g. BSIT.</p></div>
<div><label class="block text-sm font-semibold mb-1.5">Course Name</label><input class="input" name="name" value="{{ old('name') }}" required placeholder="Bachelor of Science in Information Technology"></div>
<div><label class="block text-sm font-semibold mb-1.5">Office</label><select class="input" name="office_id"><option value="">None</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id')==$office->id)>{{ $office->name }}</option>@endforeach</select></div>
<div><label class="block text-sm font-semibold mb-1.5">Assigned Staff</label><select class="input" name="staff_id"><option value="">Unassigned</option>@foreach($staff as $member)<option value="{{ $member->id }}" @selected(old('staff_id')==$member->id)>{{ $member->name }} — {{ $member->office->name ?? 'Unassigned' }}</option>@endforeach</select><p class="text-xs text-slate-500 mt-1">The staff account (Registrar, etc.) handling this course.</p></div>
<div class="flex gap-2"><button class="btn-primary">Add Course</button><a class="btn-secondary" href="{{ route('admin.courses.index') }}">Cancel</a></div></form></div>
@endsection