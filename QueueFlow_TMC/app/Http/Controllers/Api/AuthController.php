<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\AvatarStore;
use App\Support\ApiPresenter;
use App\Support\PlainPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * QueueFlow TMC API Authentication Controller
 *
 * Handles:
 * - Sign-in for every role
 * - Logout
 * - Current account
 * - Profile updates
 * - Password changes
 *
 * AN ACCOUNT IS IDENTIFIED BY WHATEVER THE VISITOR TYPED
 *
 * A student signs in with their Student ID; staff and administrators sign in
 * with their email address. Rather than branching on the role first - which
 * would need to know the role before it knows who is signing in - the single
 * value submitted as `identifier` is resolved by User::findByIdentifier(),
 * the same lookup the browser portal uses. One code path, so the two front
 * doors cannot disagree about who an identifier belongs to.
 *
 * Every role is a legitimate API caller. The student uses the Flutter app, and
 * the staff/admin endpoints under /api/v1 (staff bootstrap, admin overview,
 * the counter operations) are unreachable without a token for those roles, so
 * refusing them here would strand them at the door. What each role may then do
 * is decided by the `role:` middleware on the route, not by sign-in.
 */
class AuthController extends ApiController
{
    /**
     * Sign In
     *
     * POST /api/v1/auth/login
     * POST /api/login
     *
     * Required:
     * - identifier  the Student ID of a student, or the email of a staff
     *                member or administrator
     * - password
     *
     * Optional:
     * - device_name  the token is replaced per device, so signing in again
     *                from the same name revokes the previous token
     *
     * `email` and `student_id` are still accepted as the field name, because a
     * deployed client sends whichever one it was built against and all three
     * fold into the same lookup - and, in RouteServiceProvider::credentialKey(),
     * into the same rate-limit bucket, so renaming the field cannot be used to
     * buy extra guesses.
     */
    public function login(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        |
        | The identifier field is validated as nullable and the "at least one
        | of the three was supplied" rule is enforced immediately below, rather
        | than written as three cross-referencing `required_without` rules.
        |
        | That is not a stylistic preference. `required_without:email,student_id`
        | consults the fields it names, and in a three-way ring - each field
        | naming the other two - it reports all of them missing even when one
        | is plainly present in the body. Verified against this Laravel:
        |
        |   ['identifier' => 'required_without:email,student_id']          -> ok
        |   ['identifier' => 'required_without:email']                     -> ok
        |   all three, each naming the other two                          -> 422
        |
        | So the rule is stated once, in one place, where it can be read.
        |
        */

        $credentials = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],

            'email' => ['nullable', 'string', 'max:255'],

            'student_id' => ['nullable', 'string', 'max:255'],

            'password' => ['bail', 'required', 'string', 'max:255'],

            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $identifier = trim(
            $credentials['identifier']
                ?? $credentials['email']
                ?? $credentials['student_id']
                ?? ''
        );

        if ($identifier === '') {
            throw ValidationException::withMessages([
                'identifier' => 'Enter your Student ID or your email address.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Find the Account
        |--------------------------------------------------------------------------
        |
        | Student ID or email, any role - the same resolution the browser
        | portal uses, so an account cannot be reachable through one door and
        | invisible through the other.
        |
        */

        $user = User::findByIdentifier($identifier);

        /*
        |--------------------------------------------------------------------------
        | Verify Password
        |--------------------------------------------------------------------------
        |
        | A missing account and a wrong password produce the SAME 401 and the
        | same message. Anything more specific would turn this endpoint into a
        | lookup service for which Student IDs and email addresses are
        | registered.
        |
        */

        if (
            ! $user ||
            ! PlainPassword::check($credentials['password'], $user->password)
        ) {
            return $this->fail(
                'Those credentials do not match our records.',
                401
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Account Status
        |--------------------------------------------------------------------------
        |
        | Checked after the password, so a deactivated account is told the
        | truth about its own credentials rather than being lumped in with
        | strangers. It is still a 403 and not a 401: the password was right,
        | and the client needs to know to stop retrying and contact the
        | registrar.
        |
        */

        if (! $user->is_active) {
            return $this->fail(
                'This account has been blocked.',
                403
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Upgrade Legacy Password
        |--------------------------------------------------------------------------
        |
        | If this account still has an old password hash, convert it to the
        | password format currently used by QueueFlow after successful login.
        |
        */

        PlainPassword::upgradeIfHashed(
            $user,
            $credentials['password']
        );

        /*
        |--------------------------------------------------------------------------
        | Device
        |--------------------------------------------------------------------------
        */

        $deviceName = trim(
            $credentials['device_name'] ?? 'QueueFlow Flutter'
        );

        if ($deviceName === '') {
            $deviceName = 'QueueFlow Flutter';
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Previous Device Token
        |--------------------------------------------------------------------------
        |
        | One live token per device name. This is what stops a shared machine -
        | a lab PC, a counter terminal - accumulating tokens that nobody can
        | sign out of, and it is why signing in twice from one device leaves
        | the second token working and the first one dead.
        |
        */

        $user->tokens()
            ->where('name', $deviceName)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Create Authentication Token
        |--------------------------------------------------------------------------
        */

        $token = $user->createToken(
            $deviceName,
            $this->abilitiesFor($user)
        );

        /*
        |--------------------------------------------------------------------------
        | Staff Office Presence
        |--------------------------------------------------------------------------
        |
        | Token sign-in never touches the session guard, so the Login event
        | does not fire here. A staff API sign-in still means somebody is at
        | the counter, so the assigned office comes online the same way.
        |
        */

        if ($user->isStaff()) {
            \App\Support\OfficePresence::markOnline($user);
        }

        /*
        |--------------------------------------------------------------------------
        | Login Response
        |--------------------------------------------------------------------------
        |
        | ApiPresenter::user names its fields explicitly, so the stored password
        | is not in the payload even though the column holds it in clear text.
        |
        */

        return $this->json([
            'message' => 'Login successful.',

            'user' => ApiPresenter::user(
                $user->fresh()
            ),

            'token' => $token->plainTextToken,

            'token_type' => 'Bearer',

            'abilities' => $token->accessToken->abilities ?? [],
        ]);
    }

    /**
     * Logout
     *
     * POST /api/v1/auth/logout
     *
     * Revokes the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user
            ->currentAccessToken();

        if ($currentToken) {
            $currentToken->delete();
        }

        // Token sign-out never touches the session guard, so the Logout
        // event does not fire here. Revoking the last live session takes
        // the staff member's office offline when nobody else is present.
        if ($user->isStaff()) {
            \App\Support\OfficePresence::markOffline($user->fresh() ?? $user);
        }

        return $this->json([
            'message' => 'Signed out.',
        ]);
    }

    /**
     * Current Account
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->account($request);

        return $this->json([
            'user' => ApiPresenter::user($user),
        ]);
    }

    /**
     * Update Student Profile
     *
     * PATCH /api/v1/auth/me
     *
     * Students may update:
     * - Email
     * - Phone
     * - Avatar
     *
     * Students cannot modify (only an administrator via User Management can):
     * - Name
     * - Student ID
     * - Course
     * - Block
     * - Year level
     * - Role
     * - Account status
     */
    public function updateProfile(
        Request $request,
        AvatarStore $avatars
    ): JsonResponse {
        $user = $this->account($request);

        /*
        |--------------------------------------------------------------------------
        | Validate Profile
        |--------------------------------------------------------------------------
        */

        $data = $request->validate([
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                'unique:users,email,' . $user->id,
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'avatar' => [
                'sometimes',
                'nullable',
                'image',
                'max:' . AvatarStore::MAX_AVATAR_SIZE,
            ],

            'remove_avatar' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save Previous Avatar
        |--------------------------------------------------------------------------
        */

        $previousAvatar = $user->avatar;

        /*
        |--------------------------------------------------------------------------
        | Update Allowed Fields
        |--------------------------------------------------------------------------
        */

        $user->fill(
            Arr::only(
                $data,
                [
                    'email',
                    'phone',
                ]
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Email Verification
        |--------------------------------------------------------------------------
        |
        | Changing the email address requires email verification again.
        |
        */

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Avatar Management
        |--------------------------------------------------------------------------
        */

        if ($request->boolean('remove_avatar')) {
            $user->avatar = null;
        } elseif ($request->hasFile('avatar')) {
            $user->avatar = $avatars->store(
                $request->file('avatar')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save Account
        |--------------------------------------------------------------------------
        */

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Delete Previous Avatar
        |--------------------------------------------------------------------------
        */

        if (
            $previousAvatar !== null &&
            $previousAvatar !== $user->avatar
        ) {
            $avatars->delete($previousAvatar);
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return $this->json([
            'message' => 'Profile updated.',

            'user' => ApiPresenter::user(
                $user->fresh()
            ),
        ]);
    }

    /**
     * Change Password
     *
     * POST /api/v1/auth/password
     */
    public function changePassword(
        Request $request
    ): JsonResponse {
        $user = $this->account($request);

        /*
        |--------------------------------------------------------------------------
        | Validate Password Request
        |--------------------------------------------------------------------------
        */

        $data = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Verify Current Password
        |--------------------------------------------------------------------------
        */

        $validPassword = PlainPassword::check(
            $data['current_password'],
            $user->password
        );

        if (! $validPassword) {
            return $this->fail(
                'Your current password is incorrect.',
                422,
                [
                    'errors' => [
                        'current_password' => [
                            'Your current password is incorrect.',
                        ],
                    ],
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save New Password
        |--------------------------------------------------------------------------
        */

        $user->password = PlainPassword::make(
            $data['password']
        );

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Revoke All Existing Tokens
        |--------------------------------------------------------------------------
        |
        | The student must authenticate again using the new password.
        |
        */

        $user->tokens()->delete();

        return $this->json([
            'message' => 'Password changed. Please sign in again.',
            'reauthenticate' => true,
        ]);
    }

    /**
     * API Abilities
     *
     * Determines what each account type can access.
     */
    private function abilitiesFor(User $user): array
    {
        return match ($user->role) {
            User::ROLE_ADMIN => [
                '*',
            ],

            User::ROLE_STAFF => [
                'tickets:read',
                'tickets:operate',
                'offices:read',
                'batches:operate',
            ],

            User::ROLE_STUDENT => [
                'tickets:own',
                'profile:write',
            ],

            default => [],
        };
    }
}