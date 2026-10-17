<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The account creation and update rules, in one place.
 *
 * QueueFlow has two front doors onto the users table - the admin Accounts
 * screen and the bearer-token API the Flutter admin shell calls - and they
 * have to enforce the same rules. If each kept its own array, the API would
 * end up accepting something the form rejects, or refusing something the form
 * allows, and the difference would only show up once somebody hit it.
 *
 * Nothing is validated here; this builds the rule array and applies the
 * role-based clean-up, so callers still go through their normal `validate()`
 * or FormRequest flow.
 */
final class AccountRules
{
    /**
     * Validation rules for creating or updating an account.
     *
     * @param  User|null  $existing  the row being updated, so its own values
     *                                are not treated as duplicates of itself
     * @param  bool  $passwordRequired  true when creating, false when updating:
     *                                an update leaves the password alone unless
     *                                a new one is supplied
     * @param  array<int, string>  $allowedRoles  roles the caller may assign;
     *                                the web Add User form passes staff+student
     *                                only, while the API keeps all three
     * @return array<string, array<int, string>>
     */
    public static function build(?User $existing = null, bool $passwordRequired = false, array $allowedRoles = [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT]): array
    {
        $ignoreId = $existing?->id;

        $unique = static function (string $column) use ($ignoreId): string {
            return $ignoreId
                ? "unique:users,{$column},{$ignoreId}"
                : "unique:users,{$column}";
        };

        return [
            'name' => ['required', 'string', 'max:255'],

            // A student is identified by their Student ID, so an address is
            // optional for them and only kept so a reset code can reach them.
            // Staff and admins sign in with it, so for them it stays
            // mandatory. Unchanged from the browser portal.
            'email' => [
                'nullable',
                'required_unless:role,student',
                'email',
                'max:255',
                $unique('email'),
            ],

            'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:6'],

            'role' => ['required', 'in:'.implode(',', $allowedRoles)],

            'office_id' => ['nullable', 'exists:offices,id'],
            'student_id' => ['nullable', 'string', 'max:50', $unique('student_id'), 'required_if:role,student'],
            'course' => ['nullable', 'string', 'max:20', 'in:'.implode(',', User::COURSES), 'required_if:role,student'],
            'block' => ['nullable', 'string', 'max:10', 'required_if:role,student'],
            'year_level' => ['nullable', 'string', 'max:20', 'in:'.implode(',', User::YEAR_LEVELS), 'required_if:role,student'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Drop the fields that do not apply to the chosen role, and confirm a
     * staff account was given a usable office.
     *
     * This is the part that cannot be expressed as validation rules: "student_id
     * is null unless the role is student" is a constraint on a *combination*
     * of two fields, and Laravel has no rule for it. Left unchecked, it would
     * let an admin row keep a student_id and make sign-in ambiguous - the same
     * identifier would then match two accounts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function apply(array $data): array
    {
        if (($data['role'] ?? null) !== User::ROLE_STAFF) {
            $data['office_id'] = null;
        }

        if (($data['role'] ?? null) !== User::ROLE_STUDENT) {
            $data['student_id'] = null;
            $data['course'] = null;
            $data['block'] = null;
            $data['year_level'] = null;
        }

        if (($data['role'] ?? null) === User::ROLE_STAFF) {
            $office = Office::whereKey($data['office_id'] ?? null)
                ->assignable()
                ->first();

            if (! $office) {
                throw ValidationException::withMessages([
                    'office_id' => 'Select an active office for the staff account.',
                ]);
            }

            $data['office_id'] = $office->id;
        }

        return $data;
    }

    /**
     * Confirm an account may actually be removed.
     *
     * Two rules that exist to stop the campus being left unmanageable, and
     * that therefore have to hold on both front doors:
     *
     *   - the last administrator may not be deleted, which is how you lock
     *     everyone out with nobody left able to create accounts;
     *   - an administrator may not delete their own account, which is how you
     *     lock yourself out of the system you administer.
     *
     * The last-admin check runs first on purpose. With a single admin the two
     * rules both apply, and checked the other way round the self-delete message
     * would always win - which would make the last-admin rule unreachable from
     * any front door, and therefore untested and unguarded.
     *
     * @throws ValidationException
     */
    public static function assertRemovable(User $target, User $actor): void
    {
        if ($target->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'The last admin account cannot be deleted.',
            ]);
        }

        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }
    }

    /**
     * Rules for a password an account owner changes on themselves.
     *
     * @return array<string, array<int, string>>
     */
    public static function selfPassword(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }
}