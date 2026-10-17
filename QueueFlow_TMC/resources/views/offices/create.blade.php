@extends('layouts.admin')
@section('title','Add Office')
@section('content')
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.offices.store') }}" class="space-y-5">@csrf
<div><label class="block text-sm font-semibold mb-1.5">Office Name</label><input class="input" name="name" value="{{ old('name') }}" required placeholder="Registrar"></div>
<div><label class="block text-sm font-semibold mb-1.5">Short Code</label><input class="input" name="code" value="{{ old('code') }}" maxlength="20" required placeholder="REG-BSIT"><p class="text-xs text-slate-500 mt-1">Used for queue numbers such as REG-BSIT-001.</p></div>
<div><label class="block text-sm font-semibold mb-1.5">Description</label><textarea class="input" name="description" rows="3">{{ old('description') }}</textarea></div>
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5">Office Type</label><input class="input" name="office_type" value="{{ old('office_type') }}" maxlength="60" placeholder="e.g. Registrar counter, Cashier, Clinic"></div>
<div><label class="block text-sm font-semibold mb-1.5">Location</label><input class="input" name="location" value="{{ old('location') }}" maxlength="255" placeholder="e.g. Main Building, 2nd Floor"></div>
</div>
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5">Opening Time</label><input class="input" type="time" name="opening_time" value="{{ old('opening_time','08:00') }}"></div>
<div><label class="block text-sm font-semibold mb-1.5">Closing Time</label><input class="input" type="time" name="closing_time" value="{{ old('closing_time','17:00') }}"></div>
</div>
<div><label class="block text-sm font-semibold mb-1.5">Maximum Slots per Batch</label><input class="input" type="number" name="max_slots" min="1" max="500" value="{{ old('max_slots',100) }}" required><p class="text-xs text-slate-500 mt-1">Queue acceptance stops once this many tickets are issued per batch.</p></div>
<div class="flex gap-2"><button class="btn-primary">Add Office</button><a class="btn-secondary" href="{{ route('admin.offices.index') }}">Cancel</a></div></form></div>
@endsection