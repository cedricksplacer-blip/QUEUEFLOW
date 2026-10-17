@extends('layouts.admin')
@section('title','Edit Staff')
@section('content')
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.staff.update',$member) }}" class="space-y-5">@csrf @method('PUT')
<div class="grid md:grid-cols-2 gap-4"><div><label class="block text-sm font-semibold mb-1.5">Full Name</label><input class="input" name="name" value="{{ old('name',$member->name) }}" required></div><div><label class="block text-sm font-semibold mb-1.5">Email</label><input class="input" type="email" name="email" value="{{ old('email',$member->email) }}" required></div></div>
<div class="grid md:grid-cols-2 gap-4"><div><label class="block text-sm font-semibold mb-1.5">New Password</label><input class="input" type="password" name="password" minlength="6" placeholder="Leave blank to keep current"></div><div><label class="block text-sm font-semibold mb-1.5">Phone</label><input class="input" name="phone" value="{{ old('phone',$member->phone) }}"></div></div>
<div><label class="block text-sm font-semibold mb-1.5">Assigned Office</label><select class="input" name="office_id" required>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id',$member->office_id)==$office->id)>{{ $office->name }}</option>@endforeach</select></div>
<x-toggle name="is_active" :checked="(bool) old('is_active', $member->is_active)" label="Account is active" on="Active - can sign in and work the queue" off="Inactive - sign-in blocked" />
<div class="flex gap-2"><button class="btn-primary">Save Changes</button><a class="btn-secondary" href="{{ route('admin.staff.index') }}">Cancel</a></div></form></div>
@endsection
