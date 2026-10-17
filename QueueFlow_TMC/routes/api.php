<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QueueFlow JSON API
|--------------------------------------------------------------------------
|
| The bearer-token API consumed by the Flutter Web client. Registered from
| RouteServiceProvider under the `api-token` middleware group, which is
| deliberately `api` minus EnsureFrontendRequestsAreStateful: a request from a
| browser origin must not be carrying a session and a CSRF token, because it
| authenticates with `Authorization: Bearer <token>` and nothing else.
|
| Payloads are the shapes described in App\Support\ApiPresenter, which the Dart
| models in queueflowtmc_flutter/lib/models decode field for field. If you
| rename a key here, rename it there in the same commit.
|
| Password recovery is NOT repeated here. forgot/reset-password live in
| routes/password_recovery_api.php and already answer on both /api/v1/auth/*
| and the legacy /api/student/* shape that an older deployed client calls.
| Declaring them a third time here would only risk a shadowed route.
|
| AUTHORISATION, IN ONE PARAGRAPH, because it is the thing most likely to be
| got wrong: `auth:sanctum` means the token is valid; `role:` narrows which
| roles reach a handler; and inside the handler, guardOffice() is what stops a
| staff token acting on an office it is not assigned to. `role:admin,staff` is
| NOT the whole check - a route that mutates a queue must call guardOffice().
|
*/

// Discovery is published at BOTH /api/v1 and /api. /api/v1 is the shape
// ApiContract::discovery() advertises and the one X-Api-Version is stamped on;
// /api is kept because it is the obvious first thing to GET, and an
// unauthenticated client that lands on a bare 404 there has no way to learn
// what to call next.
Route::get('/v1', DiscoveryController::class)->name('api.v1.discovery');
Route::get('/', DiscoveryController::class)->name('api.discovery');

/*
|--------------------------------------------------------------------------
| Health (no token)
|--------------------------------------------------------------------------
|
| The liveness probe the Flutter client polls to tell "the phone has no
| network" apart from "the phone has a network but QueueFlow is not running".
| Those need different sentences and different advice, and the OS-level
| connectivity check can only answer the first one.
|
| Registered before the authenticated group and deliberately carrying no
| `throttle` middleware: it runs on every launch, on every WiFi reconnect and
| on every manual "Retry", so it has to stay callable when the campus link is
| already struggling. It is unauthenticated because the calls most worth
| diagnosing are the ones that fail before a token exists.
|
| Deliberately does no database work - see HealthController.
|
*/

Route::get('/v1/health', HealthController::class)->name('api.v1.health');
Route::get('/health', HealthController::class)->name('api.health');

/*
|--------------------------------------------------------------------------
| Sign-in (no token)
|--------------------------------------------------------------------------
|
| `student-login` is 5/minute keyed on the submitted credential AND the IP, so
| neither one account being guessed at nor one host spraying many can get free
| guesses. It is the same bucket the Blade portal uses.
|
*/

Route::post('/v1/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:student-login')
    ->name('api.v1.auth.login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:student-login')
    ->name('api.login');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::patch('/me', [AuthController::class, 'updateProfile'])->name('api.v1.auth.profile.update');
        Route::post('/password', [AuthController::class, 'changePassword'])->name('api.v1.auth.password');
    });

    /*
    |----------------------------------------------------------------------
    | Bootstrap & catalogue
    |----------------------------------------------------------------------
    |
    | One call per role. /bootstrap dispatches on the account's role and is
    | also reachable per-role explicitly, so a client that already knows its
    | role can skip the hop.
    |
    */

    Route::get('/v1/bootstrap', [PortalController::class, 'bootstrap'])->name('api.v1.bootstrap');

    Route::middleware('role:student')
        ->get('/v1/student/bootstrap', [PortalController::class, 'studentBootstrap'])
        ->name('api.v1.student.bootstrap');

    Route::middleware('role:staff')
        ->get('/v1/staff/bootstrap', [PortalController::class, 'staffBootstrap'])
        ->name('api.v1.staff.bootstrap');

    Route::middleware('role:admin')
        ->get('/v1/admin/overview', [PortalController::class, 'adminOverview'])
        ->name('api.v1.admin.overview');

    Route::get('/v1/offices', [PortalController::class, 'offices'])->name('api.v1.offices');
    Route::get('/v1/offices/{office}/live', [PortalController::class, 'live'])->name('api.v1.offices.live');
    Route::get('/v1/courses', [PortalController::class, 'courses'])->name('api.v1.courses');

            // Read-only projection of the seven Registrar course queues, each
            // with its live slot counters. Lives beside /v1/offices rather than
            // inside it because /v1/offices is deliberately narrowed to the
            // student's own college; see PortalController::registrarCourses().
            Route::get('/v1/registrar/courses', [PortalController::class, 'registrarCourses'])
                ->name('api.v1.registrar.courses');
    Route::get('/v1/announcements', [PortalController::class, 'announcementIndex'])->name('api.v1.announcements');
    Route::get('/v1/settings', [PortalController::class, 'settings'])->name('api.v1.settings');

    /*
    |----------------------------------------------------------------------
    | Tickets
    |----------------------------------------------------------------------
    |
    | POST is multipart: a PWD ticket carries an evidence photo. The body of a
    | student ticket is their own account's; staff and admin must name whose
    | ticket it is, and staff only at their own office.
    |
    */

    Route::get('/v1/tickets', [TicketController::class, 'index'])->name('api.v1.tickets');
    Route::post('/v1/tickets', [TicketController::class, 'store'])->name('api.v1.tickets.store');
    Route::get('/v1/tickets/{ticket}', [TicketController::class, 'show'])->name('api.v1.tickets.show');
    Route::post('/v1/tickets/{ticket}/cancel', [TicketController::class, 'cancel'])->name('api.v1.tickets.cancel');
    Route::get('/v1/tickets/{ticket}/evidence', [TicketController::class, 'evidence'])->name('api.v1.tickets.evidence');

    /*
    |----------------------------------------------------------------------
    | Cancellation alerts (staff: own office, admin: all offices)
    |----------------------------------------------------------------------
    */

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/v1/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index'])->name('api.v1.notifications.index');
        Route::get('/v1/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount'])->name('api.v1.notifications.unread-count');
        Route::post('/v1/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead'])->name('api.v1.notifications.read');
    });

    /*
    |----------------------------------------------------------------------
    | Counter operations
    |----------------------------------------------------------------------
    */

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/v1/queues', [QueueController::class, 'index'])->name('api.v1.queues.index');
        Route::get('/v1/queues/{office}', [QueueController::class, 'show'])->name('api.v1.queues.show');
        Route::post('/v1/queues/{office}/call-next', [QueueController::class, 'callNext'])->name('api.v1.queues.call-next');
        Route::get('/v1/queues/{office}/timeout', [QueueController::class, 'getTimeout'])->name('api.v1.queues.timeout.show');
        Route::put('/v1/queues/{office}/timeout', [QueueController::class, 'setTimeout'])->name('api.v1.queues.timeout.update');
        Route::post('/v1/queues/{office}/expire', [QueueController::class, 'expire'])->name('api.v1.queues.expire');
        Route::post('/v1/queues/{office}/tickets/{ticket}/serve', [QueueController::class, 'serve'])->name('api.v1.queues.serve');
        Route::post('/v1/queues/{office}/tickets/{ticket}/arrive', [QueueController::class, 'arrive'])->name('api.v1.queues.arrive');
        Route::post('/v1/queues/{office}/tickets/{ticket}/recall', [QueueController::class, 'recall'])->name('api.v1.queues.recall');
        Route::post('/v1/queues/{office}/tickets/{ticket}/cancel', [QueueController::class, 'cancelCall'])->name('api.v1.queues.cancel');
        Route::post('/v1/queues/{office}/tickets/{ticket}/no-show', [QueueController::class, 'noShow'])->name('api.v1.queues.no-show');
    });

    // The office-scoped update checks the office then delegates to updateStatus,
    // which is the same method the flat PUT below calls.
    Route::middleware('role:admin')
        ->put('/v1/queues/{office}/tickets/{ticket}', [QueueController::class, 'updateTicket'])
        ->name('api.v1.queues.update');

    // Flat versioned spelling of the same update: the office comes from the
    // ticket's own row (see QueueController::updateStatus). Without this,
    // a versioned client calling PUT /api/v1/queues/{ticket} - the shape
    // every other Flutter call uses - lands on a 404 while the identical
    // unversioned alias works. Same method, same authorisation, no new logic.
    Route::middleware('role:admin')
        ->put('/v1/queues/{ticket}', [QueueController::class, 'updateStatus'])
        ->name('api.v1.queues.update.flat');

    /*
    |----------------------------------------------------------------------
    | Batch lifecycle
    |----------------------------------------------------------------------
    |
    | Deploy / close / complete / open a fresh batch for one office, mirroring
    | the four controls on the admin Offices screen and the staff equivalents
    | on the counter console. All four run the same QueueDeploymentManager.
    |
    */

    Route::middleware('role:admin,staff')->prefix('v1/offices/{office}')->group(function () {
        Route::post('/deploy', [QueueController::class, 'deployBatch'])->name('api.v1.offices.deploy');
        Route::post('/close-batch', [QueueController::class, 'closeBatch'])->name('api.v1.offices.close-batch');
        Route::post('/complete-batch', [QueueController::class, 'completeBatch'])->name('api.v1.offices.complete-batch');
        Route::post('/start-batch', [QueueController::class, 'startBatch'])->name('api.v1.offices.start-batch');
        Route::post('/status', [QueueController::class, 'setStatus'])->name('api.v1.offices.status');
    });

    /*
    |----------------------------------------------------------------------
    | Accounts (admin)
    |----------------------------------------------------------------------
    */

    Route::middleware('role:admin')->prefix('v1/accounts')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('api.v1.accounts.index');
        Route::post('/', [AccountController::class, 'store'])->name('api.v1.accounts.store');
        Route::get('/options/offices', [AccountController::class, 'officeOptions'])->name('api.v1.accounts.offices');
        Route::get('/{user}', [AccountController::class, 'show'])->name('api.v1.accounts.show');
        Route::patch('/{user}', [AccountController::class, 'update'])->name('api.v1.accounts.update');
        Route::delete('/{user}', [AccountController::class, 'destroy'])->name('api.v1.accounts.destroy');
        Route::post('/{user}/restore', [AccountController::class, 'restore'])->name('api.v1.accounts.restore');
    });
});

/*
|--------------------------------------------------------------------------
| Unversioned aliases
|--------------------------------------------------------------------------
|
| The short paths some clients and the integration notes are written against.
| These are aliases, not a second implementation: each is the same controller
| method as its /api/v1/... counterpart, registered inside the same middleware
| groups, so a request cannot reach different authorisation or different
| business logic by taking the shorter path. If one of these ever needs
| different behaviour, the fix belongs in the shared method.
|
| DELETE /queues/{ticket} points at TicketController::cancel rather than at a
| status write, because "take this out of the queue" is what a caller means,
| and cancel also completes the batch when that was its last ticket.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/user', [AuthController::class, 'me'])->name('api.user');
    Route::patch('/user', [AuthController::class, 'updateProfile'])->name('api.user.update');
    Route::post('/password', [AuthController::class, 'changePassword'])->name('api.password');

    Route::get('/offices', [PortalController::class, 'offices'])->name('api.offices');
    Route::get('/offices/{office}/live', [PortalController::class, 'live'])->name('api.offices.live');

    Route::get('/tickets', [TicketController::class, 'index'])->name('api.tickets');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('api.tickets.show');
    Route::post('/tickets/{ticket}/cancel', [TicketController::class, 'cancel'])->name('api.tickets.cancel');
    Route::get('/tickets/{ticket}/evidence', [TicketController::class, 'evidence'])->name('api.tickets.evidence');

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/queues', [QueueController::class, 'index'])->name('api.queues.index');
        Route::get('/queues/{office}', [QueueController::class, 'show'])->name('api.queues.show');
        Route::post('/queues/{office}/call-next', [QueueController::class, 'callNext'])->name('api.queues.call-next');
        Route::get('/queues/{office}/timeout', [QueueController::class, 'getTimeout'])->name('api.queues.timeout.show');
        Route::put('/queues/{office}/timeout', [QueueController::class, 'setTimeout'])->name('api.queues.timeout.update');
        Route::post('/queues/{office}/expire', [QueueController::class, 'expire'])->name('api.queues.expire');
        Route::post('/queues/{office}/tickets/{ticket}/serve', [QueueController::class, 'serve'])->name('api.queues.serve');
        Route::post('/queues/{office}/tickets/{ticket}/arrive', [QueueController::class, 'arrive'])->name('api.queues.arrive');
        Route::post('/queues/{office}/tickets/{ticket}/recall', [QueueController::class, 'recall'])->name('api.queues.recall');
        Route::post('/queues/{office}/tickets/{ticket}/cancel', [QueueController::class, 'cancelCall'])->name('api.queues.cancel');
        Route::post('/queues/{office}/tickets/{ticket}/no-show', [QueueController::class, 'noShow'])->name('api.queues.no-show');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [AccountController::class, 'index'])->name('api.users');
        Route::post('/users', [AccountController::class, 'store'])->name('api.users.store');
        Route::get('/users/{user}', [AccountController::class, 'show'])->name('api.users.show');
        Route::patch('/users/{user}', [AccountController::class, 'update'])->name('api.users.update');
        Route::delete('/users/{user}', [AccountController::class, 'destroy'])->name('api.users.destroy');
        Route::post('/users/{user}/restore', [AccountController::class, 'restore'])->name('api.users.restore');

        // The office comes from the ticket's own row.
        Route::put('/queues/{ticket}', [QueueController::class, 'updateStatus'])->name('api.queues.update');
    });
});

/*
| Taking a queue ticket has to sit outside those two blocks: a student joins
| the queue they came for, which is neither an admin nor a staff action.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/queues', [TicketController::class, 'store'])->name('api.queues.store');
    Route::delete('/queues/{ticket}', [TicketController::class, 'cancel'])->name('api.queues.destroy');
});