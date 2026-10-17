<?php

namespace App\Http\Controllers\Api;

use App\Models\Office;
use App\Models\User;
use App\Support\AccountRules;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Account administration over the bearer-token API.
 *
 * This is the JSON face of the admin Accounts screen, not a second set of
 * rules: every field constraint comes from App\Support\AccountRules, which
 * UserController also uses. Adding a validation rule in one place therefore
 * cannot leave the other front door accepting something the form would reject.
 *
 * The filter keys below (role, status, search) mirror UserController::index()
 * so the two list views stay in step.
 */
class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        $users = User::with('office')
            ->when(
                $request->filled('role') && in_array($request->input('role'), [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT], true),
                fn ($q) => $q->where('role', $request->input('role'))
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('is_active', $request->input('status') === 'active')
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                // Escape LIKE wildcards in the search term. Without this a
                // student typing "100%" in the admin search box matches every
                // row, which reads as "search is broken".
                $search = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($request->input('search')));
                $q->where(fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(500)
            ->get();

        return $this->json([
            'accounts' => ApiPresenter::users($users->all()),
            'total' => $users->count(),
            'counts' => [
                'total' => User::count(),
                'students' => User::where('role', User::ROLE_STUDENT)->count(),
                'staff' => User::where('role', User::ROLE_STAFF)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            ],
        ]);
    }

    /**
     * POST /api/v1/accounts
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        $data = $request->validate(AccountRules::build(null, true));
        $data = AccountRules::apply($data);

        $password = $data['password'];
        unset($data['password']);

        $user = User::create([
            ...$data,
            'password' => $password,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->json([
            'message' => 'Account created.',
            'account' => $this->accountPayload($user),
        ], 201);
    }

    /**
     * GET /api/v1/accounts/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        return $this->json(['account' => $this->accountPayload($user)]);
    }

    /**
     * PATCH /api/v1/accounts/{user}
     *
     * The two self-edit guards from the admin form are enforced here too, and
     * for the same reason: an administrator who demotes themselves cannot get
     * back in, and an administrator who deactivates themselves would be locked
     * out on the next request.
     *
     * @throws ValidationException
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $this->account($request);
        $this->guardRoles($actor, User::ROLE_ADMIN);

        $data = $request->validate(AccountRules::build($user, false));
        $data = AccountRules::apply($data);

        if ($user->id === $actor->id && $data['role'] !== User::ROLE_ADMIN) {
            return $this->fail('You cannot remove the admin role from your own account.', 422, [
                'errors' => ['role' => ['You cannot remove the admin role from your own account.']],
            ]);
        }

        $password = $data['password'] ?? null;
        unset($data['password']);

        $user->fill($data);
        $user->is_active = $request->boolean('is_active');

        if (filled($password)) {
            $user->password = $password;

            // A password set by an administrator invalidates every session the
            // account had. That is the point of a reset: if the reason for it
            // was that someone else had access, their tokens have to go.
            $user->tokens()->delete();
        }

        if ($user->id === $actor->id) {
            $user->is_active = true;
        }

        $user->save();

        return $this->json([
            'message' => 'Account updated.',
            'account' => $this->accountPayload($user->fresh()),
        ]);
    }

    /**
     * DELETE /api/v1/accounts/{user}
     *
     * Deactivates rather than hard-deletes.
     *
     * The row is referenced by queue_tickets, and a ticket is the record that
     * a person stood in a line; deleting the account would orphan that history
     * and, because the client resolves `served_by` through a join, would blank
     * the operator's name on every ticket they ever closed. The account stops
     * being able to sign in, and the history stays readable.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $this->account($request);
        $this->guardRoles($actor, User::ROLE_ADMIN);

        try {
            AccountRules::assertRemovable($user, $actor);
        } catch (ValidationException $e) {
            return $this->fail(
                collect($e->errors())->flatten()->first(),
                422,
                ['errors' => $e->errors()]
            );
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return $this->json([
            'message' => "{$user->name}'s account has been deactivated.",
            'account' => $this->accountPayload($user->fresh()),
            'deactivated' => true,
        ]);
    }

    /**
     * POST /api/v1/accounts/{user}/restore
     *
     * The way back from a deactivation, since destroy() is not destructive.
     */
    public function restore(Request $request, User $user): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        if ($user->is_active) {
            return $this->fail('This account is already active.', 422);
        }

        $user->update(['is_active' => true]);

        return $this->json([
            'message' => "{$user->name}'s account has been reactivated.",
            'account' => $this->accountPayload($user->fresh()),
        ]);
    }

    /**
     * The offices a staff account may be assigned to, for the client's picker.
     *
     * Narrow to id/name/code because the picker only shows those, and a staff
     * account's office must be an active operational one - the same
     * restriction AccountRules::apply() enforces on write, so the picker
     * cannot offer a choice that would then be refused.
     */
    public function officeOptions(Request $request): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN);

        $offices = Office::assignable()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return $this->json([
            'offices' => $offices->map(fn (Office $office): array => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])->all(),
        ]);
    }
}