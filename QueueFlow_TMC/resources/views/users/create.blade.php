@extends('layouts.admin')
@section('title','Add User')
@section('content')
@php $courses=\App\Models\User::COURSES; $yearLevels=\App\Models\User::YEAR_LEVELS; @endphp
<div class="max-w-2xl card p-6"><form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5">@csrf
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5" for="name">Full Name</label><input class="input" id="name" name="name" value="{{ old('name') }}" required></div>
<div id="student-id-wrap"><label class="block text-sm font-semibold mb-1.5" for="student_id">Student ID</label><input class="input" id="student_id" name="student_id" value="{{ old('student_id') }}" placeholder="2026-0001"><p class="text-xs text-slate-500 mt-1">This is what the student types to sign in, along with their password.</p></div>
</div>
<div id="email-wrap">
<label class="block text-sm font-semibold mb-1.5" for="email" id="email-label">Email</label>
<input class="input" type="email" id="email" name="email" value="{{ old('email') }}">
<p class="text-xs text-slate-500 mt-1" id="email-hint">Staff sign in with this address.</p>
</div>
<div class="grid md:grid-cols-2 gap-4">
<div><label class="block text-sm font-semibold mb-1.5" for="password">Password</label><input class="input" id="password" type="password" name="password" required minlength="6"></div>
<div><label class="block text-sm font-semibold mb-1.5" for="phone">Phone</label><input class="input" id="phone" name="phone" value="{{ old('phone') }}"></div>
</div>
<div><label class="block text-sm font-semibold mb-1.5" for="role">Role</label><select class="input" id="role" name="role" required><option value="student" @selected(old('role','student')==='student')>Student</option><option value="staff" @selected(old('role')==='staff')>Staff</option></select></div>
<div id="student-wrap">
<div class="grid md:grid-cols-3 gap-4">
<div><label class="block text-sm font-semibold mb-1.5" for="block">Block</label><input class="input" id="block" name="block" value="{{ old('block') }}" placeholder="e.g. 1 or 2"></div>
<div><label class="block text-sm font-semibold mb-1.5" for="course">Course</label><select class="input" id="course" name="course"><option value="">Select course</option>@foreach($courses as $course)<option value="{{ $course }}" @selected(old('course')===$course)>{{ $course }}</option>@endforeach</select></div>
<div><label class="block text-sm font-semibold mb-1.5" for="year_level">Year Level</label><select class="input" id="year_level" name="year_level"><option value="">Select year level</option>@foreach($yearLevels as $level)<option value="{{ $level }}" @selected(old('year_level')===$level)>{{ $level }}</option>@endforeach</select></div>
</div>
</div>
<div id="office-wrap"><label class="block text-sm font-semibold mb-1.5" for="office_id">Office (staff only)</label><select class="input" id="office_id" name="office_id"><option value="">None</option>@foreach($offices as $office)<option value="{{ $office->id }}" @selected(old('office_id')==$office->id)>{{ $office->name }}</option>@endforeach</select></div>
<div class="flex gap-2"><button class="btn-primary">Create User</button><a class="btn-secondary" href="{{ route('admin.users.index') }}">Cancel</a></div></form></div>
<script>
(function(){
    var r=document.getElementById('role'),
        officeWrap=document.getElementById('office-wrap'),
        studentWrap=document.getElementById('student-wrap'),
        idStudentWrap=document.getElementById('student-id-wrap'),
        email=document.getElementById('email'),
        emailLabel=document.getElementById('email-label'),
        emailHint=document.getElementById('email-hint');
    var si=document.getElementById('student_id'),co=document.getElementById('course'),bl=document.getElementById('block'),yl=document.getElementById('year_level');
    function toggle(){
        var isStudent=r.value==='student',isStaff=r.value==='staff';
        officeWrap.style.display=isStaff?'block':'none';
        studentWrap.style.display=isStudent?'block':'none';
        idStudentWrap.style.display=isStudent?'block':'none';
        // A student is identified by Student ID, so their address is optional
        // and is only kept for password reset mail. Everyone else signs in
        // with it, so it has to be filled in.
        emailLabel.textContent=isStudent?'Email (optional)':'Email';
        emailHint.textContent=isStudent
            ?'Only used to send password reset codes. Leave blank if the student has no address.'
            :'Staff sign in with this address.';
        if(isStudent){email.removeAttribute('required');}else{email.setAttribute('required','required');}
        si.disabled=!isStudent;
        ['course','block','year_level'].forEach(function(id){
            var el=document.getElementById(id);
            if(isStudent){el.setAttribute('required','required');el.disabled=false;}else{el.removeAttribute('required');el.disabled=true;}
        });
    }
    r.addEventListener('change',toggle);
    toggle();
})();
</script>
@endsection
