<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\User;
use App\Support\AccountRules;
use App\Support\OfficePresence;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('office');

        if ($request->filled('role') && in_array($request->role, [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT], true)) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('student_id', 'like', "%{$search}%"));
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $offices = Office::assignable()
            ->orderBy('name')
            ->get();

        return view('users.create', compact('offices'));
    }

    public function store(Request $request)
    {
        // The Add User form offers staff + student only: admin accounts can
        // never be created here, even by a forged request.
        $validated = $this->validated($request, null, true, [User::ROLE_STAFF, User::ROLE_STUDENT]);
        $password = $validated['password'];
        unset($validated['password']);

        User::create([
            ...$validated,
            'password' => $password,
            'is_active' => true,
        ]);

        return redirect()->route('admin.users.index')->with('status', 'Account created.');
    }

    public function edit(User $user)
    {
        $offices = Office::assignable()
            ->orderBy('name')
            ->get();

        return view('users.edit', compact('user', 'offices'));
    }

    public function update(Request $request, User $user)
    {
        // The edit form carries no role control: the stored role is the only
        // truth. Any submitted value that differs from it is a forged request
        // and is refused before validation, so related rules (email/student
        // fields) still evaluate against the account's real role below.
        if ($request->has('role') && $request->input('role') !== $user->role) {
            return back()->withErrors(['role' => 'The role cannot be changed.'])->withInput();
        }

        $request->merge(['role' => $user->role]);

        $validated = $this->validated($request, $user, false, [$user->role]);
        $password = $validated['password'] ?? null;
        unset($validated['password']);

        if ($user->id === auth()->id() && $validated['role'] !== User::ROLE_ADMIN) {
            return back()->withErrors(['role' => 'You cannot remove the admin role from your own account.'])->withInput();
        }

        $user->fill($validated);
        $user->is_active = $request->boolean('is_active');

        if (filled($password)) {
            $user->password = $password;
        }

        if ($user->id === auth()->id()) {
            $user->is_active = true;
        }

        $user->save();

        return redirect()->route('admin.users.index')->with('status', 'Account updated.');
    }

    public function destroy(User $user)
    {
        // Admin accounts are never removed through User Management.
        if ($user->isAdmin()) {
            return back()->withErrors(['user' => 'Admin accounts cannot be deleted.']);
        }

        try {
            AccountRules::assertRemovable($user, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $user->delete();

        return back()->with('status', 'Account removed.');
    }

    /**
     * Block a staff or student account: it keeps its row and history but can
     * no longer sign in on the web or with an API token (tokens are revoked
     * here, and every login gate refuses inactive accounts).
     */
    public function block(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['user' => 'Admin accounts cannot be blocked.']);
        }

        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot block your own account.']);
        }

        if (! $user->is_active) {
            return back()->with('status', "{$user->name}'s account is already blocked.");
        }

        $user->is_active = false;
        $user->save();
        $user->tokens()->delete();
        OfficePresence::markOffline($user);

        return back()->with('status', "{$user->name}'s account has been blocked. They can no longer sign in.");
    }

    /**
     * Restore a blocked account.
     */
    public function unblock(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['user' => 'Admin accounts cannot be blocked.']);
        }

        if ($user->is_active) {
            return back()->with('status', "{$user->name}'s account is already active.");
        }

        $user->is_active = true;
        $user->save();

        return back()->with('status', "{$user->name}'s account has been unblocked.");
    }

    /**
     * The account rules now live in App\Support\AccountRules because the
     * bearer-token API validates with the same ones; see that class for why.
     * Only the two calls that differ from the API - the two self-edit guards
     * below - stay here.
     */
    private function validated(Request $request, ?User $user, bool $passwordRequired, array $allowedRoles = [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT]): array
    {
        $validated = $request->validate(
            AccountRules::build($user, $passwordRequired, $allowedRoles)
        );

        return AccountRules::apply($validated);
    }
}

