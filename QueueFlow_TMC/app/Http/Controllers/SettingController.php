<?php

namespace App\Http\Controllers;

use App\Models\QueueTicket;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class SettingController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index()
    {
        $defaults = [

            // General
            'site_name' => 'QueueFlow TMC',
            'college_name' => 'TMC College',
            'system_description' => '',
            'contact_email' => '',
            'contact_number' => '',

            // Queue
            'daily_reset_time' => '00:00',
            'default_waiting_time' => '5',
            'max_active_tickets_per_student' => '1',
            'queue_starting_value' => '001',

            // Office
            'default_opening_time' => '08:00',
            'default_closing_time' => '17:00',
            'default_lunch_break' => '12:00 PM - 1:00 PM',

            // Student / User
            'allow_student_registration' => '1',
            'allow_student_profile_edit' => '1',
            'allow_change_password' => '1',

            // Notifications
            'ticket_created_notification' => '1',
            'queue_almost_ready_notification' => '1',
            'now_serving_notification' => '1',
            'ticket_completed_notification' => '1',
            'ticket_cancelled_notification' => '1',
            'announcement_notification' => '1',

            // Security
            'session_timeout' => '30',
            'max_login_attempts' => '5',
            'lockout_duration' => '15',
            'minimum_password_length' => '8',

            // Appearance
            'primary_color' => '#073B91',
            'secondary_color' => '#1264D8',
            'accent_color' => '#FFB300',

            // 0 = OFF, 1 = ON
            'dark_mode' => '0',

            'show_system_logo' => '1',

            // Maintenance
            'maintenance_mode' => '0',
            'maintenance_message' =>
                'QueueFlow TMC is currently under maintenance.',

            // Data
            'data_retention_period' => '365',
            'last_backup' => '',
        ];


        /*
        |--------------------------------------------------------------------------
        | LOAD SETTINGS FROM MYSQL
        |--------------------------------------------------------------------------
        */

        $settings = [];

        foreach ($defaults as $key => $default) {
            $settings[$key] = Setting::get(
                $key,
                $default
            );
        }


        /*
        |--------------------------------------------------------------------------
        | FORCE DARK MODE VALUE TO 0 OR 1
        |--------------------------------------------------------------------------
        |
        | This prevents unexpected values from breaking the Blade
        | dark-mode condition.
        |
        */

        $settings['dark_mode'] =
            (string) $settings['dark_mode'] === '1'
                ? '1'
                : '0';


        /*
        |--------------------------------------------------------------------------
        | DATABASE CONNECTION STATUS
        |--------------------------------------------------------------------------
        */

        $databaseConnected = false;

        try {

            DB::connection()->getPdo();

            $databaseConnected = true;

        } catch (Throwable $e) {

            $databaseConnected = false;
        }


        return view(
            'settings.index',
            compact(
                'settings',
                'databaseConnected'
            )
        );
    }


    /**
     * Update all settings.
     */
    public function update(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([

            // General
            'site_name' => [
                'required',
                'string',
                'max:255',
            ],

            'college_name' => [
                'required',
                'string',
                'max:255',
            ],

            'system_description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'contact_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'contact_number' => [
                'nullable',
                'string',
                'max:50',
            ],

            // Queue
            'daily_reset_time' => [
                'required',
                'date_format:H:i',
            ],

            'default_waiting_time' => [
                'required',
                'integer',
                'min:1',
                'max:120',
            ],

            'max_active_tickets_per_student' => [
                'required',
                'integer',
                'min:1',
                'max:10',
            ],

            'queue_starting_value' => [
                'required',
                'string',
                'max:10',
            ],

            // Office
            'default_opening_time' => [
                'required',
                'date_format:H:i',
            ],

            'default_closing_time' => [
                'required',
                'date_format:H:i',
            ],

            'default_lunch_break' => [
                'required',
                'string',
                'max:100',
            ],

            // Security
            'session_timeout' => [
                'required',
                'integer',
                'min:1',
                'max:1440',
            ],

            'max_login_attempts' => [
                'required',
                'integer',
                'min:1',
                'max:20',
            ],

            'lockout_duration' => [
                'required',
                'integer',
                'min:1',
                'max:1440',
            ],

            'minimum_password_length' => [
                'required',
                'integer',
                'min:4',
                'max:64',
            ],

            // Appearance
            'primary_color' => [
                'required',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],

            'secondary_color' => [
                'required',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],

            'accent_color' => [
                'required',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],

            // Maintenance
            'maintenance_message' => [
                'required',
                'string',
                'max:500',
            ],

            // Data
            'data_retention_period' => [
                'required',
                'integer',
                'min:1',
                'max:3650',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | NORMAL SETTINGS
        |--------------------------------------------------------------------------
        */

        $normalSettings = [

            // General
            'site_name',
            'college_name',
            'system_description',
            'contact_email',
            'contact_number',

            // Queue
            'daily_reset_time',
            'default_waiting_time',
            'max_active_tickets_per_student',
            'queue_starting_value',

            // Office
            'default_opening_time',
            'default_closing_time',
            'default_lunch_break',

            // Security
            'session_timeout',
            'max_login_attempts',
            'lockout_duration',
            'minimum_password_length',

            // Appearance
            'primary_color',
            'secondary_color',
            'accent_color',

            // Maintenance
            'maintenance_message',

            // Data
            'data_retention_period',
        ];


        foreach ($normalSettings as $key) {

            if (array_key_exists($key, $validated)) {

                Setting::set(
                    $key,
                    (string) $validated[$key]
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | BOOLEAN SETTINGS
        |--------------------------------------------------------------------------
        */

        $booleanSettings = [

            'allow_student_registration',
            'allow_student_profile_edit',
            'allow_change_password',

            'ticket_created_notification',
            'queue_almost_ready_notification',
            'now_serving_notification',
            'ticket_completed_notification',
            'ticket_cancelled_notification',
            'announcement_notification',

            'dark_mode',
            'show_system_logo',

            'maintenance_mode',
        ];


        foreach ($booleanSettings as $key) {

            Setting::set(
                $key,
                $request->boolean($key)
                    ? '1'
                    : '0'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        return back()->with(
            'success',
            'Settings saved successfully.'
        );
    }


    /**
     * Update ONLY dark mode.
     *
     * This is intentionally separate from the main settings form.
     */
    public function updateDarkMode(Request $request)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | GET DARK MODE VALUE
            |--------------------------------------------------------------------------
            */

            $darkMode = $request->boolean('dark_mode')
                ? '1'
                : '0';


            /*
            |--------------------------------------------------------------------------
            | SAVE TO MYSQL
            |--------------------------------------------------------------------------
            */

            Setting::set(
                'dark_mode',
                $darkMode
            );


            /*
            |--------------------------------------------------------------------------
            | RETURN JSON FOR AJAX REQUEST
            |--------------------------------------------------------------------------
            */

            if ($request->expectsJson()) {

                return response()->json([
                    'success' => true,
                    'dark_mode' => $darkMode,
                    'message' => 'Dark mode updated successfully.',
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | NORMAL REQUEST
            |--------------------------------------------------------------------------
            */

            return back()->with(
                'success',
                'Dark mode updated successfully.'
            );

        } catch (Throwable $e) {

            if ($request->expectsJson()) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Unable to update dark mode: ' .
                        $e->getMessage(),
                ], 500);
            }


            return back()->withErrors([
                'dark_mode' =>
                    'Unable to update dark mode: ' .
                    $e->getMessage(),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CLEAR SYSTEM CACHE
    |--------------------------------------------------------------------------
    */

    public function clearCache()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | MAKE SURE FILE CACHE DIRECTORY EXISTS
            |--------------------------------------------------------------------------
            */

            $cacheDirectory = storage_path(
                'framework/cache/data'
            );

            File::ensureDirectoryExists(
                $cacheDirectory
            );


            /*
            |--------------------------------------------------------------------------
            | CLEAR FILE CACHE
            |--------------------------------------------------------------------------
            */

            File::cleanDirectory(
                $cacheDirectory
            );


            /*
            |--------------------------------------------------------------------------
            | CLEAR CONFIG
            |--------------------------------------------------------------------------
            */

            Artisan::call(
                'config:clear'
            );


            /*
            |--------------------------------------------------------------------------
            | CLEAR ROUTES
            |--------------------------------------------------------------------------
            */

            Artisan::call(
                'route:clear'
            );


            /*
            |--------------------------------------------------------------------------
            | CLEAR VIEWS
            |--------------------------------------------------------------------------
            */

            Artisan::call(
                'view:clear'
            );


            /*
            |--------------------------------------------------------------------------
            | CLEAR EVENTS
            |--------------------------------------------------------------------------
            */

            try {

                Artisan::call(
                    'event:clear'
                );

            } catch (Throwable $e) {
                // Ignore if event cache is unavailable.
            }


            return back()->with(
                'success',
                'System cache cleared successfully.'
            );

        } catch (Throwable $e) {

            return back()->withErrors([
                'cache' =>
                    'Unable to clear system cache: ' .
                    $e->getMessage(),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESET DAILY QUEUE
    |--------------------------------------------------------------------------
    */

    public function resetQueue()
    {
        try {

            $deleted = QueueTicket::whereDate(
                'created_at',
                today()
            )->delete();


            return back()->with(
                'success',
                "Daily queue reset successfully. {$deleted} ticket(s) removed."
            );

        } catch (Throwable $e) {

            return back()->withErrors([
                'queue' =>
                    'Unable to reset the daily queue: ' .
                    $e->getMessage(),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CLEAR OLD QUEUE RECORDS
    |--------------------------------------------------------------------------
    */

    public function clearOldQueueRecords()
    {
        try {

            $days = (int) Setting::get(
                'data_retention_period',
                365
            );


            $cutoffDate = now()->subDays(
                $days
            );


            $deleted = QueueTicket::where(
                'created_at',
                '<',
                $cutoffDate
            )->delete();


            return back()->with(
                'success',
                "Old queue records cleared successfully. {$deleted} record(s) removed."
            );

        } catch (Throwable $e) {

            return back()->withErrors([
                'queue' =>
                    'Unable to clear old queue records: ' .
                    $e->getMessage(),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE DATABASE BACKUP
    |--------------------------------------------------------------------------
    */

    public function backupDatabase()
    {
        $backupDirectory = storage_path(
            'app/backups'
        );

        try {

            File::ensureDirectoryExists(
                $backupDirectory
            );


            $filename =
                'queueflow_backup_' .
                now()->format('Y_m_d_His') .
                '.sql';


            $path =
                $backupDirectory .
                DIRECTORY_SEPARATOR .
                $filename;


            $database = config(
                'database.connections.mysql'
            );


            $dumpBinary = env(
                'MYSQLDUMP_PATH',
                'mysqldump'
            );


            $command = [

                $dumpBinary,

                '--host=' .
                    ($database['host'] ?? '127.0.0.1'),

                '--port=' .
                    ($database['port'] ?? 3306),

                '--user=' .
                    ($database['username'] ?? 'root'),

                '--single-transaction',

                '--routines',

                '--triggers',

                '--add-drop-table',

                '--no-tablespaces',

                $database['database'],
            ];


            $environment = [];


            if (
                isset($database['password']) &&
                $database['password'] !== null &&
                $database['password'] !== ''
            ) {

                $environment['MYSQL_PWD'] =
                    $database['password'];
            }


            $process = new Process(
                $command,
                base_path(),
                $environment
            );


            $process->setTimeout(300);

            $process->run();


            if (! $process->isSuccessful()) {

                if (file_exists($path)) {
                    @unlink($path);
                }


                $error = trim(
                    $process->getErrorOutput()
                );


                if ($error === '') {

                    $error =
                        'Database backup failed. ' .
                        'Make sure mysqldump is installed ' .
                        'and available in your PATH.';
                }


                return back()->withErrors([
                    'backup' => $error,
                ]);
            }


            file_put_contents(
                $path,
                $process->getOutput()
            );


            if (
                ! file_exists($path) ||
                filesize($path) === 0
            ) {

                return back()->withErrors([
                    'backup' =>
                        'The database backup file could not be created.',
                ]);
            }


            Setting::set(
                'last_backup',
                now()->format('Y-m-d H:i:s')
            );


            return back()->with(
                'success',
                "Database backup created successfully: {$filename}"
            );

        } catch (Throwable $e) {

            if (
                isset($path) &&
                file_exists($path)
            ) {
                @unlink($path);
            }


            return back()->withErrors([
                'backup' =>
                    'Database backup failed: ' .
                    $e->getMessage(),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DOWNLOAD LATEST DATABASE BACKUP
    |--------------------------------------------------------------------------
    */

    public function downloadBackup()
    {
        $backupDirectory = storage_path(
            'app/backups'
        );


        if (! is_dir($backupDirectory)) {

            return back()->withErrors([
                'backup' =>
                    'No database backup is available to download.',
            ]);
        }


        $files = glob(
            $backupDirectory .
            DIRECTORY_SEPARATOR .
            'queueflow_backup_*.sql'
        ) ?: [];


        if (empty($files)) {

            return back()->withErrors([
                'backup' =>
                    'No database backup is available to download.',
            ]);
        }


        usort(
            $files,
            function ($a, $b) {

                return filemtime($b)
                    <=> filemtime($a);
            }
        );


        $latestBackup = $files[0];


        return response()->download(
            $latestBackup,
            basename($latestBackup),
            [
                'Content-Type' =>
                    'application/sql',
            ]
        );
    }
}