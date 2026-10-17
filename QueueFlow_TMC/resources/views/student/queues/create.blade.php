@extends('layouts.student')
@section('title','Take a Ticket')
@section('content')
<div class="student-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#bcd3f5">QueueFlow TMC</p>
    <h2 class="text-xl font-bold mt-1">Take a Queue Ticket</h2>
    <p class="text-xs mt-1" style="color:#d7e6fb">Enrollment operations are open from 8:00 AM to 3:00 PM. Each station targets 40–60 students per day and can accept up to 100 tickets per deployment.</p>
</div>

@if(session('status'))
<div class="card p-4 mb-4 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if($errors->any())
<div class="card p-4 mb-4 text-sm text-red-700"><ul class="list-disc ml-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="grid gap-4">
@foreach($offices as $item)
@php
    $office = $item['office'];
    $course = $item['course'];
    $submitting = $item['submitting'];
    $online = $item['online'];
    $courseCode = $course ? strtoupper($course->code) : null;
    $schedule = ($office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : '8:00 AM')
        . ' – ' . ($office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : '3:00 PM');
@endphp
<div class="card p-4 office-card" data-office="{{ $office->id }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h3 class="font-bold text-slate-900">{{ $office->name }}</h3>
            @if($course)
            <p class="text-[11px] font-semibold text-blue-600 mt-1">{{ $course->code }} — {{ $course->name }}</p>
            @endif
            <p class="text-xs text-slate-500 mt-1">{{ $office->description }}</p>
            <p class="text-xs text-slate-500 mt-1">Operational window: <span class="font-semibold">{{ $schedule }}</span> · 40–60 target/day · 100 max slots</p>
        </div>
    </div>

    @if($submitting)
    <form method="POST" action="{{ route('student.queues.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3 queue-form" data-course-code="{{ $courseCode }}">
        @csrf
        <input type="hidden" name="office_id" value="{{ $office->id }}">
        <input type="hidden" name="course_id" class="course-id" value="{{ $course?->id }}">
        <div>
            <label class="block text-xs font-semibold mb-1 text-slate-600">Service</label>
            <select class="input service-select" name="service_id" required>
                <option value="">Select service</option>
                @foreach($office->services as $service)
                    @php $station=$service->station; @endphp
                    @if($station && !$station->acceptsCourse($courseCode)) @continue @endif
                    <option value="{{ $service->id }}" data-courses="{{ implode(',', $station?->course_codes ?? []) }}">
                        {{ $service->name }} @if($station) — {{ $station->location }} @endif
                    </option>
                @endforeach
            </select>
            <p class="text-[11px] text-slate-400 mt-1 service-location"></p>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1 text-slate-600">Priority</label>
            <select class="input" name="priority" data-priority required>
                <option value="regular">Regular</option>
                <option value="pwd">PWD (Person with Disability)</option>
            </select>
        </div>
        <div class="hidden" data-evidence>
            <label class="block text-xs font-semibold mb-1 text-slate-600">PWD Evidence</label>
            <input class="input" type="file" name="priority_evidence" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <p class="text-[11px] text-slate-400 mt-1">JPG, JPEG, PNG or WEBP. Max 2 MB.</p>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1 text-slate-600">Purpose <span class="text-slate-400 font-normal">(optional)</span></label>
            <input class="input" name="purpose" maxlength="255" placeholder="Brief reason for your transaction">
        </div>
        <button type="submit" class="btn-primary w-full">Get Queue Number</button>
    </form>
    @else
        @if(!$online)
        <div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700">
            This office is currently offline and is not accepting queue tickets. Please wait for the staff to switch the office online.
        </div>
        @else
        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
            Queue is currently closed. The station may have reached its 100-slot deployment limit or the 8:00 AM–3:00 PM operating window has ended.
        </div>
        @endif
    @endif
</div>
@endforeach
</div>

<script>
(function(){
    document.querySelectorAll('.queue-form').forEach(function(form){
        const priority=form.querySelector('[data-priority]');
        const evidence=form.querySelector('[data-evidence]');
        const file=evidence.querySelector('input[name="priority_evidence"]');
        const service=form.querySelector('.service-select');
        const location=form.querySelector('.service-location');

        function syncPriority(){
            if(priority.value==='pwd'){ evidence.classList.remove('hidden'); file.required=true; }
            else { evidence.classList.add('hidden'); file.required=false; file.value=''; }
        }
        function syncLocation(){
            const opt=service.options[service.selectedIndex];
            location.textContent=opt && opt.value ? (opt.textContent||'').replace(/.*— /,'').trim() : '';
        }
        priority.addEventListener('change',syncPriority);
        service.addEventListener('change',syncLocation);
        syncPriority(); syncLocation();
    });
})();
</script>
@endsection