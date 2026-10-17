<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Office;
use App\Models\User;
use App\Support\OfficePresence;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', User::ROLE_STAFF)->with('office', 'assignedCourses');

        if ($request->filled('office_id')) {
            $query->where('office_id', $request->integer('office_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $staff = $query->orderBy('name')->paginate(12)->withQueryString();
        $offices = Office::assignable()
            ->orderBy('name')
            ->get();

        return view('staff.index', compact('staff', 'offices'));
    }

    public function create()
    {
        $offices = Office::assignable()
            ->orderBy('name')
            ->get();

        return view('staff.create', compact('offices'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'office_id' => ['required', 'exists:offices,id'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $office = Office::whereKey($validated['office_id'])
            ->assignable()
            ->firstOrFail();

        $staff = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'office_id' => $office->id,
            'phone' => $validated['phone'] ?? null,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $this->syncCourseAssignment($staff);

        return redirect()->route('admin.staff.index')->with('status', 'Staff account created.');
    }

    public function edit(User $staff)
    {
        abort_unless($staff->role === User::ROLE_STAFF, 404);
        $offices = Office::assignable()
            ->orderBy('name')
            ->get();

        return view('staff.edit', ['member' => $staff, 'offices' => $offices]);
    }

    public function update(Request $request, User $staff)
    {
        abort_unless($staff->role === User::ROLE_STAFF, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$staff->id],
            'password' => ['nullable', 'string', 'min:6'],
            'office_id' => ['required', 'exists:offices,id'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $office = Office::whereKey($validated['office_id'])
            ->assignable()
            ->firstOrFail();

        $officeChanged = (int) $staff->office_id !== (int) $office->id;

        $staff->name = $validated['name'];
        $staff->email = $validated['email'];
        $staff->office_id = $office->id;
        $staff->phone = $validated['phone'] ?? null;
        $staff->is_active = $request->boolean('is_active');

        if (filled($validated['password'] ?? null)) {
            $staff->password = $validated['password'];
        }

        $staff->save();

        if ($officeChanged) {
            $this->clearCourseAssignment($staff);
        }

        $this->syncCourseAssignment($staff);

        return redirect()->route('admin.staff.index')->with('status', 'Staff account updated.');
    }

    public function destroy(User $staff)
    {
        abort_unless($staff->role === User::ROLE_STAFF, 404);

        $this->clearCourseAssignment($staff);

        $staff->delete();

        return back()->with('status', 'Staff account removed.');
    }

    /**
     * Keep courses.staff_id consistent with users.office_id for Registrar
     * course offices, so the Courses page "Assigned Staff" column, the Staff
     * Management "Assigned Courses" badges and the staff console all agree
     * on who handles each of the seven course queues.
     *
     * Only acts when the staff member sits on a REG-<COURSE> office; every
     * other office (Cashier, Admissions, SASO, Scholarship, Clinic) has no
     * course row and is left untouched.
     */
    private function syncCourseAssignment(User $staff): void
    {
        $office = $staff->office;

        if (! $office || ! str_starts_with(strtoupper((string) $office->code), 'REG-')) {
            return;
        }

        $course = Course::where('office_id', $office->id)->first()
            ?? Course::where('code', substr(strtoupper((string) $office->code), 4))->first();

        if (! $course) {
            return;
        }

        if ((int) $course->office_id !== (int) $office->id) {
            $course->office_id = $office->id;
        }

        $course->staff_id = $staff->id;
        $course->save();
    }

    /**
     * Drop every course assignment pointing at this staff member, used when
     * they move offices or their account is removed so a course queue never
     * names someone who no longer handles it.
     */
    private function clearCourseAssignment(User $staff): void
    {
        Course::where('staff_id', $staff->id)->update(['staff_id' => null]);
    }

    /**
     * Block a staff account: the row and its history stay, but the account
     * can no longer sign in on the web or with an API token (tokens are
     * revoked here, and every login gate plus the auth middleware refuses
     * inactive accounts). Their office goes offline unless another active
     * staff member is still using it.
     */
    public function block(Request $request, User $staff)
    {
        abort_unless($staff->role === User::ROLE_STAFF, 404);

        if (! $staff->is_active) {
            return back()->with('status', "{$staff->name}'s account is already blocked.");
        }

        $staff->is_active = false;
        $staff->save();
        $staff->tokens()->delete();
        OfficePresence::markOffline($staff);

        return back()->with('status', "{$staff->name}'s account has been blocked. They can no longer sign in.");
    }

    /**
     * Restore a blocked staff account.
     */
    public function unblock(Request $request, User $staff)
    {
        abort_unless($staff->role === User::ROLE_STAFF, 404);

        if ($staff->is_active) {
            return back()->with('status', "{$staff->name}'s account is already active.");
        }

        $staff->is_active = true;
        $staff->save();

        return back()->with('status', "{$staff->name}'s account has been unblocked.");
    }
}

