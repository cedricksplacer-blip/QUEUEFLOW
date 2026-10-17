<?php

/**
 * One-off helper: create a student account so the mobile portal can be
 * exercised end to end. Run with:  php bootstrap_student.php
 */
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Course;
use App\Models\Office;
use App\Models\Setting;
use App\Models\User;

$user = User::updateOrCreate(
    ['email' => 'student@tmc.edu'],
    [
        'name' => 'Juan Dela Cruz',
        'password' => 'password',
        'role' => User::ROLE_STUDENT,
        'student_id' => '2024-00123',
        'course' => 'BSIT',
        'block' => '1',
        'year_level' => '2nd Year',
        'is_active' => true,
    ]
);

echo 'student id: '.$user->id.PHP_EOL;

// The BSIT course row is missing from the migrated database, which blocks any
// REG-BSIT queue request because course_id is required. Re-attach it.
$bsitOffice = Office::where('code', 'REG-BSIT')->first();

if ($bsitOffice) {
    Course::updateOrCreate(
        ['code' => 'BSIT'],
        [
            'name' => 'Bachelor of Science in Information Technology',
            'office_id' => $bsitOffice->id,
            'is_active' => true,
        ]
    );

    echo 'restored BSIT course -> office '.$bsitOffice->id.PHP_EOL;
}

echo 'courses: '.Course::pluck('code')->implode(', ').PHP_EOL;
echo 'active offices: '.Office::where('is_active', true)->pluck('code')->implode(', ').PHP_EOL;
echo 'max active tickets: '.Setting::get('max_active_tickets_per_student').PHP_EOL;

// The announcements table is empty, so both the website and the app would have
// nothing to show. Seed the same notice DatabaseSeeder ships with.
$announcement = App\Models\Announcement::firstOrCreate(
    ['title' => 'QueueFlow TMC Enrollment Operations'],
    [
        'body' => 'QueueFlow TMC services are available from 8:00 AM to 3:00 PM. Each office can accept up to 100 queue slots per deployment. Select the office and service that matches your transaction.',
        'is_active' => true,
        'status' => App\Models\Announcement::STATUS_PUBLISHED,
        'posted_by' => $user->id,
    ]
);

echo 'announcement: #'.$announcement->id.' '.$announcement->title.PHP_EOL;
