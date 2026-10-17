<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Http\Controllers\Traits\StaffPreferences;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class StaffSettingsController extends Controller
{
    use StaffOfficeScope, StaffPreferences;

    /**
     * Staff-only settings page: appearance, queue-console preferences
     * and password change. Every control here is scoped to the logged-in
     * staff member.
     */
    public function index(Request $request)
    {
        $office = $this->officeOf($request);

        $user = $request->user();

        $preferences = [
            'dark_mode' => $this->staffPref($user, 'dark_mode', '0'),
            'auto_call' => $this->staffPref($user, 'auto_call', '0'),
            'show_names' => $this->staffPref($user, 'show_names', '1'),
            'confirm_served' => $this->staffPref($user, 'confirm_served', '1'),
        ];

        return view('staff.settings.index', compact('office', 'user', 'preferences'));
    }

    /**
     * Save the staff member's preferences.
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        Setting::set($this->staffPrefKey($user, 'dark_mode'), $request->boolean('dark_mode') ? '1' : '0');
        Setting::set($this->staffPrefKey($user, 'auto_call'), $request->boolean('auto_call') ? '1' : '0');
        Setting::set($this->staffPrefKey($user, 'show_names'), $request->boolean('show_names') ? '1' : '0');
        Setting::set($this->staffPrefKey($user, 'confirm_served'), $request->boolean('confirm_served') ? '1' : '0');

        return back()->with('status', 'Your preferences were saved.');
    }

    /**
     * Restore this staff member's preferences to their default values.
     */
    public function resetPreferences(Request $request)
    {
        $user = $request->user();

        Setting::set($this->staffPrefKey($user, 'dark_mode'), '0');
        Setting::set($this->staffPrefKey($user, 'auto_call'), '0');
        Setting::set($this->staffPrefKey($user, 'show_names'), '1');
        Setting::set($this->staffPrefKey($user, 'confirm_served'), '1');

        return back()->with('status', 'Your preferences were reset to defaults.');
    }

    /**
     * Change the staff member's password.
     */
    public function updatePassword(Request $request)
    {
        $minLength = (int) Setting::get('minimum_password_length', 8);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(max($minLength, 8))],
        ]);

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        return back()->with('status', 'Your password has been updated.');
    }
}