<?php

namespace App\Http\Controllers\Traits;

use App\Models\Office;
use Illuminate\Http\Request;

trait StaffOfficeScope
{
    protected function officeOf(Request $request): Office
    {
        $user = $request->user();

        abort_unless($user?->isStaff(), 403, 'Only staff accounts can access an office.');

        $office = $user->office()
            ->assignable()
            ->first();

        abort_unless($office, 403, 'Your staff account is not assigned to an active operational office.');

        return $office;
    }
}
