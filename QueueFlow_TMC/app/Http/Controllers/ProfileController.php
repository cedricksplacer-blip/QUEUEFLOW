<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\AvatarStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, AvatarStore $avatars): RedirectResponse
    {
        $user = $request->user();

        // The avatar is an uploaded file, not a column value, so it is kept
        // out of fill() and applied separately below.
        $user->fill($request->safe()->except(['avatar', 'remove_avatar']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $replacedAvatar = $this->syncAvatar($request, $user, $avatars);

        $user->save();

        // Only unlink the previous file once the row has actually changed, so a
        // failed save never destroys the photo the user still has on record.
        if ($replacedAvatar !== null && $replacedAvatar !== $user->avatar) {
            $avatars->delete($replacedAvatar);
        }

        // The admin-styled profile page sets redirect_to=admin so saving keeps
        // the administrator inside the admin layout. This is a fixed flag, not
        // a URL, so it cannot be used to build an open redirect.
        $toAdmin = $request->input('redirect_to') === 'admin';

        $status = $user->avatar === null && $replacedAvatar !== null
            ? 'avatar-removed'
            : 'profile-updated';

        if ($toAdmin) {
            return Redirect::route('admin.profile.edit')->with('status', $status);
        }

        return Redirect::route('profile.edit')->with('status', $status);
    }

    /**
     * Apply an avatar upload, a removal, or nothing at all.
     *
     * The replacement file is written to disk before the model is saved and the
     * previous file is only unlinked afterwards, so a rejected upload cannot
     * leave an orphaned file behind and cannot lose the existing photo.
     *
     * @return string|null the superseded path, when one should be deleted
     */
    private function syncAvatar(ProfileUpdateRequest $request, User $user, AvatarStore $avatars): ?string
    {
        $previous = $user->avatar;

        if ($request->boolean('remove_avatar')) {
            $user->avatar = null;

            return $previous;
        }

        $upload = $request->file('avatar');

        // Nothing submitted: leave the current photo exactly as it is.
        if ($upload === null) {
            return null;
        }

        $user->avatar = $avatars->store($upload);

        return $previous;
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
