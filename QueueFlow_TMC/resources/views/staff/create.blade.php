@extends('layouts.admin')
@section('title','Add Staff')
@section('content')
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.staff.store') }}" class="space-y-5">@csrf
<div class="grid md:grid-cols-2 gap-4"><div><label class="block text-sm font-semibold mb-1.5">Full Name</label><input class="input" name="name" value="{{ old('name') }}" required></div><div><label class="block text-sm font-semibold mb-1.5">Email</label><input class="input" type="email" name="email" value="{{ old('email') }}" required></div></div>
<div class="grid md:grid-cols-2 gap-4"><div><label class="block text-sm font-semibold mb-1.5">Password</label><input class="input" type="password" name="password" required minlength="6"></div><div><label class="block text-sm font-semibold mb-1.5">Phone</label><input class="input" name="phone" value="{{ old('phone') }}"></div></div>
<div><label class="block text-sm font-semibold mb-1.5">Assigned Office</label><select class="input" name="office_id" required><option value="">Select office</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id')==$office->id)>{{ $office->name }}</option>@endforeach</select></div>
<div class="flex gap-2"><button class="btn-primary">Create Staff</button><a class="btn-secondary" href="{{ route('admin.staff.index') }}">Cancel</a></div></form></div>
@endsection
