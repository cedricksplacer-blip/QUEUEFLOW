<?php

namespace App\Http\Controllers\Traits;

use App\Models\Setting;
use App\Models\User;

trait StaffPreferences
{
    /**
     * Per-staff preference key so each staff member keeps their own
     * settings without touching the global administrator settings.
     */
    protected function staffPrefKey(User $user, string $key): string
    {
        return "staff_{$user->id}_{$key}";
    }

    /**
     * Read a per-staff preference value.
     */
    protected function staffPref(User $user, string $key, string $default = '0'): string
    {
        return Setting::get($this->staffPrefKey($user, $key), $default);
    }
}