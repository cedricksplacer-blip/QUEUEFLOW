<?php

use App\Http\Controllers\Api\StudentAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Password Recovery API
|--------------------------------------------------------------------------
|
| The only JSON surface QueueFlow still exposes.
|
| Every other /api route group has been removed:
|
|   routes/api_v1.php        19 endpoints  /api/v1/*        student mobile v1
|   routes/student_api.php   18 endpoints  /api/student/*   student mobile legacy
|   routes/staff_api.php      7 endpoints  /api/staff/*     staff mobile
|   routes/api.php           50 endpoints  /api/*           admin JSON panel
|
| What is left is password recovery, because a student who is locked out has
| no other way back in: the web portal signs in with a Student ID and the
| admin panel can only be reached by someone who is already signed in, so an
| account with a forgotten password would otherwise be permanently stranded.
|
| BOTH URL SHAPES ARE KEPT ON PURPOSE
| /api/v1/auth/* is the current contract; /api/student/* is what the
| already-deployed Flutter build calls. They hit the same controller, so a
| build that has not been updated keeps working, and a new one can move to the
| versioned path. Everything else about the mobile app is gone, so a student
| can recover a password here but cannot reach a queue from a phone.
|
| Both routes stay stateless on the `api` middleware group, so they are
| CSRF-free and callable from a native client. Each stacks `student-reset`
| on top, which is a separate, more generous bucket than `student-login` on
| purpose: a student who has just burned five failed sign-in attempts must
| still be able to ask for a reset code.
|
*/

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('forgot-password', [StudentAuthController::class, 'forgotPassword'])
            ->middleware('throttle:student-reset');

        Route::post('verify-code', [StudentAuthController::class, 'verifyCode'])
            ->middleware('throttle:student-reset');

        Route::post('reset-password', [StudentAuthController::class, 'resetPassword'])
            ->middleware('throttle:student-reset');
    });
});

Route::prefix('student')->group(function () {
    Route::post('forgot-password', [StudentAuthController::class, 'forgotPassword'])
        ->middleware('throttle:student-reset');

    Route::post('verify-code', [StudentAuthController::class, 'verifyCode'])
        ->middleware('throttle:student-reset');

    Route::post('reset-password', [StudentAuthController::class, 'resetPassword'])
        ->middleware('throttle:student-reset');
});
