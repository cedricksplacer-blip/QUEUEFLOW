@extends('layouts.admin')
@section('title','Edit Office')
@section('content')
@php $deployment=$office->activeDeployment; @endphp
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.offices.update',$office) }}" class="space-y-5">@csrf @method('PUT')
<div><label class="block text-sm font-semibold mb-1.5">Office Name</label><input class="input" name="name" value="{{ old('name',$office->name) }}" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Short Code</label><input class="input" name="code" value="{{ old('code',$office->code) }}" maxlength="20" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Description</label><textarea class="input" name="description" rows="3">{{ old('description',$office->description) }}</textarea></div>
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5">Office Type</label><input class="input" name="office_type" value="{{ old('office_type',$office->office_type) }}" maxlength="60" placeholder="e.g. Registrar counter, Cashier, Clinic"></div>
<div><label class="block text-sm font-semibold mb-1.5">Location</label><input class="input" name="location" value="{{ old('location',$office->location) }}" maxlength="255" placeholder="e.g. Main Building, 2nd Floor"></div>
</div>
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5">Opening Time</label><input class="input" type="time" name="opening_time" value="{{ old('opening_time', $office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('H:i') : '08:00') }}"></div>
<div><label class="block text-sm font-semibold mb-1.5">Closing Time</label><input class="input" type="time" name="closing_time" value="{{ old('closing_time', $office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('H:i') : '17:00') }}"></div>
</div>
<div><label class="block text-sm font-semibold mb-1.5">Maximum Slots per Batch</label><input class="input" type="number" name="max_slots" min="1" max="500" value="{{ old('max_slots',$office->max_slots) }}" required></div>
<x-toggle name="is_active" :checked="(bool) old('is_active',$office->is_active)" label="Office is active" on="Active - selectable and deployable" off="Inactive - hidden from pickers" />
<div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500">Current batch status: <span class="font-bold uppercase">{{ $deployment ? $deployment->status . ' (Batch #' . $deployment->batch_no . ')' : 'No batch started' }}</span> — use the Offices list for deploy / close / complete actions.</div>
<div class="flex gap-2"><button class="btn-primary">Save Changes</button><a class="btn-secondary" href="{{ route('admin.offices.index') }}">Cancel</a></div></form></div>
@endsection