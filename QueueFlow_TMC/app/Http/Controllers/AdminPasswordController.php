<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PlainPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AdminPasswordController extends Controller
{
    /**
     * Show Forgot Password page.
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Send OTP to the administrator's email.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:255',
            ],
        ]);

        $email = strtolower(trim($request->email));

        /*
        |--------------------------------------------------------------------------
        | Brute Force Protection
        |--------------------------------------------------------------------------
        |
        | Throttle reset requests by email + IP so attackers cannot
        | spam the endpoint to enumerate accounts or flood the inbox.
        |
        */

        $throttleKey = 'admin-password-request:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput()
                ->withErrors([
                    'email' => 'Too many reset requests. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
                ]);
        }

        RateLimiter::hit($throttleKey, 300);

        /*
        |--------------------------------------------------------------------------
        | Find Admin Account
        |--------------------------------------------------------------------------
        */

        $user = User::where('email', $email)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();

        if (!$user) {
            return back()
                ->withInput()
                ->withErrors([
                    'email' => 'We could not find an active administrator account with that email address.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate 6-Digit OTP
        |--------------------------------------------------------------------------
        */

        $otp = (string) random_int(100000, 999999);

        /*
        |--------------------------------------------------------------------------
        | Store OTP in Session
        |--------------------------------------------------------------------------
        |
        | The OTP expires after 10 minutes.
        |
        */

        Session::put('password_reset_otp', Hash::make($otp));
        Session::put('password_reset_email', $email);
        Session::put(
            'password_reset_otp_expires',
            now()->addMinutes(10)->timestamp
        );

        // OTP has not yet been verified.
        Session::put('password_reset_verified', false);

        /*
        |--------------------------------------------------------------------------
        | Send OTP Email
        |--------------------------------------------------------------------------
        */

        try {
            Mail::raw(
                "Hello {$user->name},\n\n"
                . "Your QueueFlow TMC password reset verification code is:\n\n"
                . "{$otp}\n\n"
                . "This code will expire in 10 minutes.\n\n"
                . "If you did not request a password reset, please ignore this email.\n\n"
                . "Regards,\n"
                . "QueueFlow TMC",
                function ($message) use ($email) {
                    $message
                        ->to($email)
                        ->subject('QueueFlow TMC - Password Reset OTP');
                }
            );
        } catch (\Throwable $e) {
            Log::error(
                'QueueFlow TMC OTP email failed.',
                [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]
            );

            Session::forget([
                'password_reset_otp',
                'password_reset_email',
                'password_reset_otp_expires',
                'password_reset_verified',
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'email' => 'Unable to send the verification code. Please check the mail configuration and try again.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Go To OTP Verification
        |--------------------------------------------------------------------------
        */

        return redirect()
            ->route('admin.password.otp')
            ->with('status', 'A 6-digit verification code has been sent to your email address.');
    }

    /**
     * Show OTP verification page.
     */
    public function showOtp()
    {
        $email = Session::get('password_reset_email');

        if (!$email) {
            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Please request a new verification code.',
                ]);
        }

        return view('auth.verify-otp', [
            'email' => $email,
        ]);
    }

    /**
     * Verify OTP.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => [
                'bail',
                'required',
                'digits:6',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Brute Force Protection
        |--------------------------------------------------------------------------
        |
        | Throttle OTP guesses so attackers cannot brute-force the code.
        |
        */

        $throttleKey = 'admin-password-otp:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withErrors([
                    'otp' => 'Too many attempts. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
                ]);
        }

        RateLimiter::hit($throttleKey, 60);

        $storedOtp = Session::get('password_reset_otp');
        $email = Session::get('password_reset_email');
        $expiresAt = Session::get('password_reset_otp_expires');

        /*
        |--------------------------------------------------------------------------
        | Check OTP Session
        |--------------------------------------------------------------------------
        */

        if (!$storedOtp || !$email || !$expiresAt) {
            return back()
                ->withErrors([
                    'otp' => 'Your verification code has expired. Please request a new one.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Expiration
        |--------------------------------------------------------------------------
        */

        if (now()->timestamp > $expiresAt) {
            Session::forget([
                'password_reset_otp',
                'password_reset_email',
                'password_reset_otp_expires',
                'password_reset_verified',
            ]);

            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Your verification code has expired. Please request a new one.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify OTP
        |--------------------------------------------------------------------------
        */

        if (!Hash::check($request->otp, $storedOtp)) {
            return back()
                ->withErrors([
                    'otp' => 'The verification code is incorrect.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | OTP Successfully Verified
        |--------------------------------------------------------------------------
        */

        RateLimiter::clear($throttleKey);

        Session::put('password_reset_verified', true);

        // OTP is single-use.
        Session::forget('password_reset_otp');

        return redirect()
            ->route('admin.password.new')
            ->with(
                'status',
                'Verification successful. You can now create a new password.'
            );
    }

    /**
     * Show new password page.
     */
    public function showNewPassword()
    {
        $email = Session::get('password_reset_email');
        $verified = Session::get('password_reset_verified', false);

        if (!$email || !$verified) {
            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Please verify your email using the OTP first.',
                ]);
        }

        return view('auth.reset-password', [
            'email' => $email,
        ]);
    }

    /**
     * Update password.
     */
    public function resetPassword(Request $request)
    {
        $email = Session::get('password_reset_email');
        $verified = Session::get('password_reset_verified', false);

        /*
        |--------------------------------------------------------------------------
        | Make Sure OTP Was Verified
        |--------------------------------------------------------------------------
        */

        if (!$email || !$verified) {
            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Please verify your email using the OTP first.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate New Password
        |--------------------------------------------------------------------------
        */

        $request->validate([
            'password' => [
                'bail',
                'required',
                'string',
                'min:6',
                'max:255',
                'confirmed',
                'regex:/^\S+$/',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Find Admin
        |--------------------------------------------------------------------------
        */

        $user = User::where('email', $email)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();

        if (!$user) {
            Session::forget([
                'password_reset_email',
                'password_reset_verified',
            ]);

            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'The administrator account could not be found.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Save New Password
        |--------------------------------------------------------------------------
        */

        $user->password = PlainPassword::make($request->password);

        // Invalidate existing remember-me sessions.
        $user->setRememberToken(
            \Illuminate\Support\Str::random(60)
        );

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Clear Password Reset Session
        |--------------------------------------------------------------------------
        */

        Session::forget([
            'password_reset_otp',
            'password_reset_email',
            'password_reset_otp_expires',
            'password_reset_verified',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Return To Admin Login
        |--------------------------------------------------------------------------
        */

        return redirect()
            ->route('admin.login')
            ->with(
                'status',
                'Your password has been reset successfully. You can now sign in.'
            );
    }

    /**
     * Resend OTP.
     */
    public function resendOtp()
    {
        $email = Session::get('password_reset_email');

        if (!$email) {
            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Please enter your email address again.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Brute Force Protection
        |--------------------------------------------------------------------------
        */

        $throttleKey = 'admin-password-resend:' . $email . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withErrors([
                    'otp' => 'Too many resend requests. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
                ]);
        }

        RateLimiter::hit($throttleKey, 300);

        /*
        |--------------------------------------------------------------------------
        | Find Admin
        |--------------------------------------------------------------------------
        */

        $user = User::where('email', $email)
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();

        if (!$user) {
            return redirect()
                ->route('admin.password.request')
                ->withErrors([
                    'email' => 'Administrator account not found.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate New OTP
        |--------------------------------------------------------------------------
        */

        $otp = (string) random_int(100000, 999999);

        Session::put('password_reset_otp', Hash::make($otp));
        Session::put('password_reset_otp_expires', now()->addMinutes(10)->timestamp);
        Session::put('password_reset_verified', false);

        /*
        |--------------------------------------------------------------------------
        | Send Email
        |--------------------------------------------------------------------------
        */

        try {
            Mail::raw(
                "Hello {$user->name},\n\n"
                . "Your new QueueFlow TMC password reset verification code is:\n\n"
                . "{$otp}\n\n"
                . "This code will expire in 10 minutes.\n\n"
                . "If you did not request a password reset, please ignore this email.\n\n"
                . "Regards,\n"
                . "QueueFlow TMC",
                function ($message) use ($email) {
                    $message
                        ->to($email)
                        ->subject('QueueFlow TMC - New Password Reset OTP');
                }
            );
        } catch (\Throwable $e) {
            Log::error(
                'QueueFlow TMC resend OTP email failed.',
                [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]
            );

            return back()
                ->withErrors([
                    'otp' => 'Unable to send a new verification code. Please try again.',
                ]);
        }

        return redirect()
            ->route('admin.password.otp')
            ->with(
                'status',
                'A new verification code has been sent to your email.'
            );
    }
}