<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\AdminPasswordController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\OfficeStationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffDashboardController;
use App\Http\Controllers\StaffDeploymentController;
use App\Http\Controllers\StaffInfoController;
use App\Http\Controllers\StaffQueueController;
use App\Http\Controllers\StaffSettingsController;
use App\Http\Controllers\StudentQueueController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn () =>
    redirect()->route('admin.login')
)->name('home');


/*
|--------------------------------------------------------------------------
| Admin Login
|--------------------------------------------------------------------------
*/

Route::get('/admin/login', [
    AdminLoginController::class,
    'create'
])->name('admin.login');

Route::post('/admin/login', [
    AdminLoginController::class,
    'store'
])->name('admin.login.store');


/*
|--------------------------------------------------------------------------
| Admin Password Recovery - OTP
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Forgot Password
|--------------------------------------------------------------------------
|
| User enters their administrator email address.
|
*/

Route::get('/admin/forgot-password', [
    AdminPasswordController::class,
    'showForgotPassword'
])->name('admin.password.request');

Route::post('/admin/forgot-password', [
    AdminPasswordController::class,
    'sendResetLink'
])->name('admin.password.email');


/*
|--------------------------------------------------------------------------
| Verify OTP
|--------------------------------------------------------------------------
|
| User enters the 6-digit OTP received through Gmail.
|
*/

Route::get('/admin/verify-otp', [
    AdminPasswordController::class,
    'showOtp'
])->name('admin.password.otp');

Route::post('/admin/verify-otp', [
    AdminPasswordController::class,
    'verifyOtp'
])->name('admin.password.verify');


/*
|--------------------------------------------------------------------------
| Resend OTP
|--------------------------------------------------------------------------
*/

Route::post('/admin/resend-otp', [
    AdminPasswordController::class,
    'resendOtp'
])->name('admin.password.resend');


/*
|--------------------------------------------------------------------------
| New Password
|--------------------------------------------------------------------------
|
| This page can only be accessed after successful OTP verification.
|
*/

Route::get('/admin/new-password', [
    AdminPasswordController::class,
    'showNewPassword'
])->name('admin.password.new');

Route::post('/admin/new-password', [
    AdminPasswordController::class,
    'resetPassword'
])->name('admin.password.update');


/*
|--------------------------------------------------------------------------
| Admin Logout
|--------------------------------------------------------------------------
*/

Route::post('/admin/logout', [
    AdminLoginController::class,
    'destroy'
])->middleware('auth')->name('admin.logout');


/*
|--------------------------------------------------------------------------
| Unified Staff & Administrator Access
|--------------------------------------------------------------------------
|
| Staff and administrator accounts use the same login form. Staff accounts
| are automatically scoped to the active office assigned to their account.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/staff/login', function () {
        return redirect()->route('admin.login');
    })->name('staff.login');
});

Route::post('/staff/logout', [
    AdminLoginController::class,
    'destroy'
])->middleware('auth')->name('staff.logout');


/*
|--------------------------------------------------------------------------
| Admin Panel
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [
            AdminDashboardController::class,
            'index'
        ])->name('dashboard');

        // Live counts for the sidebar badges (polled, no page reload).
        Route::get('/sidebar-counts', [
            \App\Http\Controllers\SidebarBadgeController::class,
            'admin'
        ])->name('sidebar-counts');

        // Grouped recent-activity feed for the dashboard accordion.
        Route::get('/recent-activity', [
            \App\Http\Controllers\Admin\AdminDashboardController::class,
            'recentActivity'
        ])->name('recent-activity');

        // Lightweight live numbers for the dashboard stat cards + status pills.
        Route::get('/queue-summary', [
            \App\Http\Controllers\Admin\AdminDashboardController::class,
            'queueSummary'
        ])->name('queue-summary');

        // Minimal change detector for queue list pages (stale banners only).
        Route::get('/queue-ping', [
            \App\Http\Controllers\Admin\AdminDashboardController::class,
            'queuePing'
        ])->name('queue-ping');

        // Centralized Queue Activity History behind the dashboard "View all".
        Route::get('/activity', [
            \App\Http\Controllers\Admin\AdminDashboardController::class,
            'activity'
        ])->name('activity.index');


        /*
        |--------------------------------------------------------------------------
        | Queue Management
        |--------------------------------------------------------------------------
        */

        Route::get('/queues', [
            QueueController::class,
            'index'
        ])->name('queues.index');

        Route::get('/queues/create', [
            QueueController::class,
            'create'
        ])->name('queues.create');

        Route::post('/queues', [
            QueueController::class,
            'store'
        ])->name('queues.store');

        Route::post('/queues/office/{office}/call-next', [
            QueueController::class,
            'callNext'
        ])->name('queues.call-next');

        Route::patch('/queues/{ticket}/status', [
            QueueController::class,
            'updateStatus'
        ])->name('queues.status');

        Route::get('/queues/{ticket}/evidence', [
            QueueController::class,
            'evidence'
        ])->name('queues.evidence');

        Route::delete('/queues/{ticket}', [
            QueueController::class,
            'destroy'
        ])->name('queues.destroy');


        /*
        |--------------------------------------------------------------------------
        | Staff Management
        |--------------------------------------------------------------------------
        */

        Route::resource('staff', StaffController::class)
            ->except(['show']);

        Route::post('/staff/{staff}/block', [
            StaffController::class,
            'block'
        ])->name('staff.block');

        Route::post('/staff/{staff}/unblock', [
            StaffController::class,
            'unblock'
        ])->name('staff.unblock');


        /*
        |--------------------------------------------------------------------------
        | User Management
        |--------------------------------------------------------------------------
        */

        Route::resource('users', UserController::class)
            ->except(['show']);

        Route::post('/users/{user}/block', [
            UserController::class,
            'block'
        ])->name('users.block');

        Route::post('/users/{user}/unblock', [
            UserController::class,
            'unblock'
        ])->name('users.unblock');


        /*
        |--------------------------------------------------------------------------
        | Office Management
        |--------------------------------------------------------------------------
        */

        Route::resource('offices', OfficeController::class)
            ->except(['show']);

        Route::get('/offices/{office}/stations/create', [
            OfficeStationController::class,
            'create'
        ])->name('offices.stations.create');

        Route::post('/offices/{office}/stations', [
            OfficeStationController::class,
            'store'
        ])->name('offices.stations.store');

        Route::get('/offices/{office}/stations/{station}/edit', [
            OfficeStationController::class,
            'edit'
        ])->name('offices.stations.edit');

        Route::put('/offices/{office}/stations/{station}', [
            OfficeStationController::class,
            'update'
        ])->name('offices.stations.update');

        Route::delete('/offices/{office}/stations/{station}', [
            OfficeStationController::class,
            'destroy'
        ])->name('offices.stations.destroy');

        Route::post('/offices/{office}/deploy', [
            OfficeController::class,
            'deploy'
        ])->name('offices.deploy');

        Route::post('/offices/{office}/close-deployment', [
            OfficeController::class,
            'closeDeployment'
        ])->name('offices.close-deployment');

        Route::post('/offices/{office}/complete-deployment', [
            OfficeController::class,
            'completeDeployment'
        ])->name('offices.complete-deployment');

        Route::post('/offices/{office}/start-deployment', [
            OfficeController::class,
            'startDeployment'
        ])->name('offices.start-deployment');

        Route::post('/offices/{office}/toggle-active', [
            OfficeController::class,
            'toggleActive'
        ])->name('offices.toggle-active');

        Route::post('/offices/{office}/toggle-status', [
            OfficeController::class,
            'toggleStatus'
        ])->name('offices.toggle-status');


        /*
        |--------------------------------------------------------------------------
        | Course Management (Registrar course + staff assignment)
        |--------------------------------------------------------------------------
        */

        Route::resource('courses', CourseController::class)
            ->except(['show']);


        /*
        |--------------------------------------------------------------------------
        | Announcement Management
        |--------------------------------------------------------------------------
        */

        Route::resource('announcements', AnnouncementController::class)
            ->except(['show']);

        Route::post('/announcements/{announcement}/approve', [
            AnnouncementController::class,
            'approve'
        ])->name('announcements.approve');

        Route::post('/announcements/{announcement}/reject', [
            AnnouncementController::class,
            'reject'
        ])->name('announcements.reject');


        /*
        |--------------------------------------------------------------------------
        | Cancellation alerts (campus-wide)
        |--------------------------------------------------------------------------
        */

        Route::get('/notifications', [
            \App\Http\Controllers\Admin\AdminNotificationController::class,
            'index'
        ])->name('notifications.index');

        Route::get('/notifications/recent', [
            \App\Http\Controllers\Admin\AdminNotificationController::class,
            'recent'
        ])->name('notifications.recent');

        Route::get('/notifications/unread-count', [
            \App\Http\Controllers\Admin\AdminNotificationController::class,
            'unreadCount'
        ])->name('notifications.unread-count');

        Route::get('/notifications/{notification}', [
            \App\Http\Controllers\Admin\AdminNotificationController::class,
            'show'
        ])->name('notifications.show');

        Route::post('/notifications/{notification}/read', [
            \App\Http\Controllers\Admin\AdminNotificationController::class,
            'markRead'
        ])->name('notifications.read');


        /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */

        Route::get('/reports', [
            ReportController::class,
            'index'
        ])->name('reports.index');


        /*
        |--------------------------------------------------------------------------
        | Settings
        |--------------------------------------------------------------------------
        */

        Route::get('/settings', [
            SettingController::class,
            'index'
        ])->name('settings.index');

        Route::put('/settings', [
            SettingController::class,
            'update'
        ])->name('settings.update');


        /*
        |--------------------------------------------------------------------------
        | Settings - Clear System Cache
        |--------------------------------------------------------------------------
        */

        Route::post('/settings/cache/clear', [
            SettingController::class,
            'clearCache'
        ])->name('settings.cache.clear');
    

        /*
        |--------------------------------------------------------------------------
        | My Profile (admin-styled)
        |--------------------------------------------------------------------------
        |
        | The shared profile.update / password.update routes do the actual
        | saving; this only serves the page inside the admin layout.
        |
        */

        Route::get('/profile', [
            AdminProfileController::class,
            'edit'
        ])->name('profile.edit');

        // Photos live outside the web root, so they are streamed through this
        // authenticated admin route rather than linked directly.
        Route::get('/profile/avatar/{user}', [
            AdminProfileController::class,
            'avatar'
        ])->name('profile.avatar');
    


        /*
        |--------------------------------------------------------------------------
        | Settings - Reset Daily Queue
        |--------------------------------------------------------------------------
        */

        Route::post('/settings/queue/reset', [
            SettingController::class,
            'resetQueue'
        ])->name('settings.queue.reset');

        Route::post('/settings/queue/clear-old', [
            SettingController::class,
            'clearOldQueueRecords'
        ])->name('settings.queue.clear-old');


        /*
        |--------------------------------------------------------------------------
        | Settings - Database Backup
        |--------------------------------------------------------------------------
        */

        Route::post('/settings/backup', [
            SettingController::class,
            'backupDatabase'
        ])->name('settings.backup');


        /*
        |--------------------------------------------------------------------------
        | Settings - Download Database Backup
        |--------------------------------------------------------------------------
        */

        Route::get('/settings/backup/download', [
            SettingController::class,
            'downloadBackup'
        ])->name('settings.backup.download');

    });


/*
|--------------------------------------------------------------------------
| Authenticated General Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | General Dashboard Redirect
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', function (Illuminate\Http\Request $request) {
        $user = $request->user();

        if ($user->role === App\Models\User::ROLE_STUDENT) {
            return redirect()->route('student.queues.index');
        }

        if ($user->role === App\Models\User::ROLE_STAFF) {
            return redirect()->route('staff.dashboard');
        }

        return redirect()->route('admin.dashboard');
    })->name('dashboard');


    /*
    |--------------------------------------------------------------------------
    | Student Queue Desk
    |--------------------------------------------------------------------------
    |
    | Students take a queue ticket for their office, choosing a course when
    | the Registrar is selected and uploading proof when PWD priority is used.
    |
    */

    Route::get('/student/queues', [
        StudentQueueController::class,
        'index'
    ])->name('student.queues.index');

    Route::get('/student/queues/create', [
        StudentQueueController::class,
        'create'
    ])->name('student.queues.create');

    Route::post('/student/queues', [
        StudentQueueController::class,
        'store'
    ])->name('student.queues.store');

    Route::get('/student/queues/{ticket}', [
        StudentQueueController::class,
        'show'
    ])->name('student.queues.show');


    /*
    |--------------------------------------------------------------------------
    | Queue Display
    |--------------------------------------------------------------------------
    */

    Route::get('/queues/display/{office}/{course?}', [
        QueueController::class,
        'display'
    ])->name('queues.display')
        ->where(['course' => '[0-9]+']);


    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [
        ProfileController::class,
        'edit'
    ])->name('profile.edit');

    Route::patch('/profile', [
        ProfileController::class,
        'update'
    ])->name('profile.update');

    Route::delete('/profile', [
        ProfileController::class,
        'destroy'
    ])->name('profile.destroy');

});


/*
|--------------------------------------------------------------------------
| Staff Office Queue Console
|--------------------------------------------------------------------------
|
| Staff members operate the running queue for the active office assigned
| to their account only: call next, mark served and monitor waiting tickets.
|
*/

Route::middleware(['auth', 'role:staff'])
    ->prefix('staff')
    ->name('staff.')
    ->group(function () {

        Route::get('/dashboard', [
            StaffDashboardController::class,
            'index'
        ])->name('dashboard');

        Route::get('/announcements', [
            StaffInfoController::class,
            'announcements'
        ])->name('announcements.index');

        Route::get('/announcements/create', [
            StaffInfoController::class,
            'createAnnouncement'
        ])->name('announcements.create');

        Route::get('/announcements/recent', [
            StaffInfoController::class,
            'announcementFeed'
        ])->name('announcements.recent');

        Route::get('/announcements/unread-count', [
            StaffInfoController::class,
            'announcementUnreadCount'
        ])->name('announcements.unread-count');

        Route::post('/announcements', [
            StaffInfoController::class,
            'storeAnnouncement'
        ])->name('announcements.store');

        Route::post('/announcements/read-all', [
            StaffInfoController::class,
            'markAllAnnouncementsRead'
        ])->name('announcements.read-all');

        Route::get('/announcements/{announcement}', [
            StaffInfoController::class,
            'showAnnouncement'
        ])->name('announcements.show');

        Route::post('/announcements/{announcement}/read', [
            StaffInfoController::class,
            'markAnnouncementRead'
        ])->name('announcements.read');

        Route::get('/history', [
            StaffInfoController::class,
            'history'
        ])->name('history.index');

        Route::get('/office', [
            StaffInfoController::class,
            'office'
        ])->name('office.show');

        Route::post('/office/toggle-status', [
            StaffInfoController::class,
            'toggleStatus'
        ])->name('office.toggle-status');

        /*
        |--------------------------------------------------------------------------
        | Staff Settings
        |--------------------------------------------------------------------------
        |
        | Staff-only preferences (dark mode, queue-console behaviour) and
        | password change. Each setting is scoped to the logged-in staff
        | member, so one staff's preferences never affect another's.
        |
        */

        Route::get('/settings', [
            StaffSettingsController::class,
            'index'
        ])->name('settings.index');

        Route::post('/settings', [
            StaffSettingsController::class,
            'updatePreferences'
        ])->name('settings.update');

        Route::post('/settings/reset', [
            StaffSettingsController::class,
            'resetPreferences'
        ])->name('settings.reset');

        Route::post('/settings/password', [
            StaffSettingsController::class,
            'updatePassword'
        ])->name('settings.password.update');

        Route::post('/queues/call-next', [
            StaffQueueController::class,
            'callNext'
        ])->name('queues.call-next');

        Route::post('/queues/timeout', [
            StaffQueueController::class,
            'updateTimeout'
        ])->name('queues.timeout');

        Route::post('/queues/{ticket}/arrive', [
            StaffQueueController::class,
            'confirmArrival'
        ])->name('queues.arrive');

        Route::post('/queues/{ticket}/recall', [
            StaffQueueController::class,
            'recall'
        ])->name('queues.recall');

        Route::get('/queues/state', [
            StaffQueueController::class,
            'state'
        ])->name('queues.state');

        Route::post('/queues/{ticket}/served', [
            StaffQueueController::class,
            'markServed'
        ])->name('queues.served');

        Route::post('/queues/{ticket}/cancel', [
            StaffQueueController::class,
            'cancelCall'
        ])->name('queues.cancel');

        Route::post('/queues/{ticket}/no-show', [
            StaffQueueController::class,
            'markNoShow'
        ])->name('queues.no-show');

        /*
        |--------------------------------------------------------------------------
        | Batch management (any operational office)
        |--------------------------------------------------------------------------
        |
        | Staff can deploy/close/complete/start/reset their assigned office's
        | batch, mirroring the admin Offices controls. Scoped to the office
        | assigned to the staff account and powered by the same
        | QueueDeploymentManager.
        |
        */

        Route::post('/admissions/deploy', [
            StaffDeploymentController::class,
            'deploy'
        ])->name('admissions.deploy');

        Route::post('/admissions/close', [
            StaffDeploymentController::class,
            'closeDeployment'
        ])->name('admissions.close');

        Route::post('/admissions/close-batch', [
            StaffDeploymentController::class,
            'closeBatch'
        ])->name('admissions.close-batch');

        Route::post('/admissions/complete', [
            StaffDeploymentController::class,
            'completeDeployment'
        ])->name('admissions.complete');

        Route::post('/admissions/start', [
            StaffDeploymentController::class,
            'startDeployment'
        ])->name('admissions.start');

        Route::post('/admissions/reset-batch-number', [
            StaffDeploymentController::class,
            'resetBatchNumber'
        ])->name('admissions.reset-batch-number');

        Route::get('/queues/evidence/{ticket}', [
            StaffQueueController::class,
            'evidence'
        ])->name('queues.evidence');

        /*
        |--------------------------------------------------------------------------
        | Cancellation alerts (office-scoped)
        |--------------------------------------------------------------------------
        |
        | Written by TicketCancellationNotifier whenever a ticket at this
        | office moves to cancelled. Listed in the sidebar and on the
        | Cancellation Alerts pages (BROADCAST_DRIVER=log, so no WebSocket).
        |
        */

        Route::get('/notifications', [
            \App\Http\Controllers\StaffNotificationController::class,
            'index'
        ])->name('notifications.index');

        Route::get('/notifications/recent', [
            \App\Http\Controllers\StaffNotificationController::class,
            'recent'
        ])->name('notifications.recent');

        Route::get('/notifications/unread-count', [
            \App\Http\Controllers\StaffNotificationController::class,
            'unreadCount'
        ])->name('notifications.unread-count');

        // Live counts for the sidebar badges (polled, no page reload).
        Route::get('/sidebar-counts', [
            \App\Http\Controllers\SidebarBadgeController::class,
            'staff'
        ])->name('sidebar-counts');

        Route::post('/notifications/read-all', [
            \App\Http\Controllers\StaffNotificationController::class,
            'markAllRead'
        ])->name('notifications.read-all');

        Route::get('/notifications/{notification}', [
            \App\Http\Controllers\StaffNotificationController::class,
            'show'
        ])->name('notifications.show');

        Route::post('/notifications/{notification}/read', [
            \App\Http\Controllers\StaffNotificationController::class,
            'markRead'
        ])->name('notifications.read');

    });


/*
|--------------------------------------------------------------------------
| Laravel Authentication Routes
|--------------------------------------------------------------------------
|
| These are the default Laravel authentication routes.
| The QueueFlow TMC administrator password recovery uses
| the custom OTP routes above.
|
*/

require __DIR__.'/auth.php';