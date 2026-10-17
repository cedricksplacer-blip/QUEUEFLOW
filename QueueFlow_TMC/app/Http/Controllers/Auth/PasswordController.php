<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PlainPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if (! PlainPassword::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ])->errorBag('updatePassword');
        }

        $request->user()->update(['password' => PlainPassword::make($validated['password'])]);

        return back()->with('status', 'password-updated');
    }
}
