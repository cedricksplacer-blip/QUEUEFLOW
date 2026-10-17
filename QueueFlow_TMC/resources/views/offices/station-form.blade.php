@extends('layouts.admin')
@section('title', $station->exists ? 'Edit Station' : 'Add Station')
@section('content')
@php
    $editing = $station->exists;
    $action = $editing
        ? route('admin.offices.stations.update', [$office, $station])
        : route('admin.offices.stations.store', $office);
    $time = fn ($value, $fallback) => \Carbon\Carbon::parse($value ?: $fallback)->format('H:i');
@endphp
<div class="max-w-2xl">
    <div class="mb-4">
        <a href="{{ route('admin.offices.index') }}" class="text-sm text-blue-600 font-semibold">&larr; Back to Offices</a>
        <h2 class="text-lg font-bold mt-1">{{ $editing ? 'Edit Station' : 'Add Station' }} &mdash; {{ $office->name }}</h2>
    </div>

    <div class="card p-6">
        <form method="POST" action="{{ $action }}" class="space-y-5">
            @csrf
            @if($editing) @method('PUT') @endif

            <div>
                <label class="block text-sm font-semibold mb-1.5">Station Name</label>
                <input class="input" name="name" value="{{ old('name', $station->name) }}" required>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1.5">Location</label>
                <input class="input" id="location-field" name="location" value="{{ old('location', $station->location) }}" maxlength="255"
                       placeholder="Building, floor, or room">
                <p class="text-xs text-slate-500 mt-1.5">
                    The college does not publish a per-office room directory, so the campus address is used as the
                    default. Add the building, floor, or room here once you have confirmed it on the ground.
                </p>
                <button type="button" class="btn-secondary mt-2" style="height:28px;min-height:28px;padding:0 10px;font-size:11px"
                        onclick="document.getElementById('location-field').value = @js(\App\Models\OfficeStation::CAMPUS_LOCATION)">
                    Reset to campus address
                </button>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1.5">Accepted Courses</label>
                <div class="grid sm:grid-cols-2 gap-2">
                    @foreach(\App\Models\User::COURSES as $code)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="course_codes[]" value="{{ $code }}" class="rounded"
                                   @checked(in_array($code, old('course_codes', $station->course_codes ?? []), true))>
                            {{ $code }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 mt-1.5">Leave all unticked to accept any course.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1.5">Min / Day</label>
                    <input class="input" type="number" name="min_daily_students" min="0"
                           value="{{ old('min_daily_students', $station->min_daily_students ?? 40) }}" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5">Target / Day</label>
                    <input class="input" type="number" name="target_daily_students" min="0"
                           value="{{ old('target_daily_students', $station->target_daily_students ?? 60) }}" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5">Max Slots</label>
                    <input class="input" type="number" name="max_daily_slots" min="1"
                           value="{{ old('max_daily_slots', $station->max_daily_slots ?? 100) }}" required>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1.5">Opening Time</label>
                    <input class="input" type="time" name="opening_time"
                           value="{{ old('opening_time', $time($station->opening_time, '08:00')) }}" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5">Closing Time</label>
                    <input class="input" type="time" name="closing_time"
                           value="{{ old('closing_time', $time($station->closing_time, '15:00')) }}" required>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" class="rounded"
                       @checked(old('is_active', $editing ? $station->is_active : true))>
                Station is active
            </label>

            <div class="flex gap-2">
                <button class="btn-primary">{{ $editing ? 'Save Changes' : 'Add Station' }}</button>
                <a class="btn-secondary" href="{{ route('admin.offices.index') }}">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
