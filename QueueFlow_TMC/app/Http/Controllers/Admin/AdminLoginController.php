<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\User;
use App\Support\PlainPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminLoginController extends Controller
{
    /**
     * Show the unified administration and staff login page.
     */
    public function create()
    {
        return view('admin.auth.login');
    }

    /**
     * Process the unified administrator and staff login.
     */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => [
                'bail',
                'required',
                'string',
                'max:255',
            ],
        ]);

        $email = strtolower(trim($credentials['email']));
        $throttleKey = 'portal-login:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
            ]);
        }

        RateLimiter::hit($throttleKey, 60);

        $user = User::where('email', $email)->first();

        if (! $user || (! $user->isAdmin() && ! $user->isStaff())) {
            throw ValidationException::withMessages([
                'email' => 'These credentials are not valid for the QueueFlow portal.',
            ]);
        }

        if (! PlainPassword::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials are not valid for the QueueFlow portal.',
            ]);
        }

        // Correct password but blocked: say so plainly, next to the form.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been blocked.',
            ]);
        }

        // A legacy bcrypt row becomes clear text the first time it is used.
        PlainPassword::upgradeIfHashed($user, $credentials['password']);

        $office = null;

        if ($user->isStaff()) {
            $office = Office::whereKey($user->office_id)
                ->assignable()
                ->first();

            if (! $office) {
                throw ValidationException::withMessages([
                    'email' => 'This staff account is not assigned to an active office.',
                ]);
            }
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        if ($office) {
            $request->session()->put('staff_office_id', $office->id);

            return redirect()
                ->route('staff.dashboard')
                ->with('status', 'Welcome back, ' . $user->name . '! You are now operating: ' . $office->name . '.');
        }

        $request->session()->forget('staff_office_id');

        return redirect()
            ->intended(route('admin.dashboard'))
            ->with('status', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * Log the current user out.
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        /*
        |--------------------------------------------------------------------------
        | Invalidate Session
        |--------------------------------------------------------------------------
        */

        $request->session()->invalidate();

        /*
        |--------------------------------------------------------------------------
        | Regenerate CSRF Token
        |--------------------------------------------------------------------------
        */

        $request->session()->regenerateToken();

        return redirect()
            ->route('admin.login')
            ->with(
                'status',
                'You have been logged out successfully.'
            );
    }
}