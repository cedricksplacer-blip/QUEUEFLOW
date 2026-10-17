@extends('layouts.admin')
@section('title','Generate Queue')
@section('content')
<div class="max-w-3xl card p-6">
<form method="POST" action="{{ route('admin.queues.store') }}" enctype="multipart/form-data" class="space-y-5">
@csrf
<div class="grid md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-semibold mb-1.5">Office</label>
        <select class="input" name="office_id" id="office_id" required>
            <option value="">Select office</option>
            @foreach($offices as $office)
            <option value="{{ $office->id }}" data-code="{{ $office->code }}">{{ $office->name }} ({{ $office->code }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-semibold mb-1.5">Course</label>
        <select class="input" name="course_id" id="course_id" required>
            <option value="">Select course</option>
            @foreach($courses as $course)
            <option value="{{ $course->id }}" data-code="{{ $course->code }}">{{ $course->code }} — {{ $course->name }}</option>
            @endforeach
        </select>
    </div>
</div>
<div>
    <label class="block text-sm font-semibold mb-1.5">Service</label>
    <select class="input" name="service_id" id="service_id" required>
        <option value="">Select office first</option>
        @foreach($offices as $office)
            @foreach($office->services as $service)
            <option value="{{ $service->id }}" data-office="{{ $office->id }}" data-courses="{{ implode(',', $service->station?->course_codes ?? []) }}">
                {{ $office->name }} — {{ $service->name }} @if($service->station) ({{ $service->station->location }}) @endif
            </option>
            @endforeach
        @endforeach
    </select>
</div>
<div>
<label class="block text-sm font-semibold mb-1.5">Student <span class="text-slate-400 font-normal">(optional for walk-ins)</span></label>
<select class="input" name="user_id">
<option value="">Walk-in / no account</option>
@foreach($students as $student)
<option value="{{ $student->id }}">{{ $student->name }} — {{ $student->student_id ?? $student->email }} — {{ $student->course ?? 'No course' }} / {{ $student->year_level ?? 'No year' }}</option>
@endforeach
</select>
</div>
<div>
<label class="block text-sm font-semibold mb-1.5">Priority</label>
<select class="input" name="priority" id="priority" required><option value="regular">Regular</option><option value="pwd">PWD</option></select>
</div>
<div id="evidence-wrap" class="hidden"><label class="block text-sm font-semibold mb-1.5">PWD Evidence Image</label><input class="input" type="file" name="priority_evidence" id="priority_evidence" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><p class="text-xs text-slate-500 mt-1">JPG, JPEG, PNG or WEBP only. Max 2 MB.</p></div>
<div><label class="block text-sm font-semibold mb-1.5">Purpose</label><input class="input" name="purpose" maxlength="255" placeholder="Transaction purpose"></div>
<div class="rounded-xl bg-slate-50 p-4 text-xs text-slate-600">
<strong>Station policy:</strong> 8:00 AM–3:00 PM operational window · target 40–60 students per station/day · maximum 100 tickets per deployment.
</div>
<div class="flex gap-2"><button class="btn-primary">Generate Ticket</button><a class="btn-secondary" href="{{ route('admin.queues.index') }}">Cancel</a></div>
</form>
</div>
<script>
(function(){
const office=document.getElementById('office_id'), course=document.getElementById('course_id'), service=document.getElementById('service_id');
const priority=document.getElementById('priority'), wrap=document.getElementById('evidence-wrap'), evidence=document.getElementById('priority_evidence');
function sync(){
 const oid=office.value, code=(course.options[course.selectedIndex]?.dataset.code||'').toUpperCase();
 Array.from(service.options).forEach(o=>{
   if(!o.value) return;
   const allowed=(o.dataset.courses||'').split(',').map(x=>x.trim().toUpperCase()).filter(Boolean);
   o.hidden=(o.dataset.office!==oid) || (allowed.length && !allowed.includes(code));
   if(o.hidden && o.selected) service.value='';
 });
 if(!service.value) service.options[0].textContent=oid ? 'Select service' : 'Select office first';
}
function p(){if(priority.value==='pwd'){wrap.classList.remove('hidden');evidence.required=true;}else{wrap.classList.add('hidden');evidence.required=false;evidence.value='';}}
office.addEventListener('change',sync); course.addEventListener('change',sync); priority.addEventListener('change',p); sync(); p();
})();
</script>
@endsection
