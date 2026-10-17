<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Show the signed-in administrator's own profile.
     *
     * This page is presentation only. Saving reuses the shared profile.update
     * and password.update routes so the existing validation rules stay in one
     * place; the redirect_to flag is what keeps the user on this page
     * afterwards instead of being bounced to the generic profile view.
     */
    public function edit(Request $request): View
    {
        return view('admin.profile', [
            'user' => $request->user(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Profile Photo
    |--------------------------------------------------------------------------
    |
    | Photos are kept on the "local" disk, outside the public web root, so
    | they are only ever streamed back through this authenticated admin route.
    | Mirrors how PWD evidence is served.
    |
    */

    public function avatar(User $user)
    {
        abort_unless($user->hasAvatar(), 404);

        return Storage::disk('local')->response($user->avatar);
    }
}
