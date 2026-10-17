<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::with(['office', 'assignedStaff'])->orderBy('code');

        if ($request->filled('office_id')) {
            $query->where('office_id', $request->integer('office_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $courses = $query->paginate(20)->withQueryString();
        $offices = Office::orderBy('name')->get();

        return view('courses.index', compact('courses', 'offices'));
    }

    public function create()
    {
        $offices = Office::where('is_active', true)->orderBy('name')->get();
        $staff = User::where('role', User::ROLE_STAFF)->where('is_active', true)->with('office')->orderBy('name')->get();

        return view('courses.create', compact('offices', 'staff'));
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $validated['is_active'] = true;

        $course = Course::create($validated);

        $this->syncStaffOffice($course);

        return redirect()->route('admin.courses.index')->with('status', 'Course added.');
    }

    public function edit(Course $course)
    {
        $offices = Office::where('is_active', true)->orderBy('name')->get();
        $staff = User::where('role', User::ROLE_STAFF)->where('is_active', true)->with('office')->orderBy('name')->get();

        return view('courses.edit', compact('course', 'offices', 'staff'));
    }

    public function update(Request $request, Course $course)
    {
        $validated = $this->validated($request, $course->id);

        $validated['is_active'] = $request->boolean('is_active');

        $course->update($validated);

        $this->syncStaffOffice($course->fresh());

        return redirect()->route('admin.courses.index')->with('status', 'Course updated.');
    }

    /**
     * Move the assigned staff member onto this course's office, so assigning
     * someone to a Registrar course in the Courses page also scopes their
     * Staff Dashboard console (which follows users.office_id) to that
     * course's queue. Their previous course queue stops naming them.
     */
    private function syncStaffOffice(Course $course): void
    {
        if (! $course->staff_id || ! $course->office_id) {
            return;
        }

        $member = User::whereKey($course->staff_id)
            ->where('role', User::ROLE_STAFF)
            ->first();

        if (! $member) {
            return;
        }

        if ((int) $member->office_id !== (int) $course->office_id) {
            $member->office_id = $course->office_id;
            $member->save();
        }

        Course::where('staff_id', $member->id)
            ->where('id', '!=', $course->id)
            ->update(['staff_id' => null]);
    }

    public function destroy(Course $course)
    {
        $course->delete();

        return redirect()->route('admin.courses.index')->with('status', 'Course removed.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = $ignoreId ? "unique:courses,code,{$ignoreId}" : 'unique:courses,code';

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', $unique],
            'name' => ['required', 'string', 'max:255'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'staff_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                if ($value && User::whereKey($value)->value('role') !== User::ROLE_STAFF) {
                    $fail('The assigned member must be a staff account.');
                }
            }],
        ]);
    }
}