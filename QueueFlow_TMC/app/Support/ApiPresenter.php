<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Office;
use App\Models\OfficeService;
use App\Models\OfficeStation;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;

/**
 * Turns QueueFlow's Eloquent models into the JSON the Flutter client parses.
 *
 * EVERY payload the API returns is built here, and nowhere else. The Dart
 * models in queueflowtmc_flutter/lib/models decode exactly these keys, so a
 * rename here is a rename on the client too. That coupling is deliberate and
 * is why there is one file to read rather than the shape being re-decided in
 * every controller.
 *
 * WHY A PRESENTER AND NOT API RESOURCES
 * The Flutter models expect the same envelope whether one row or a list of
 * them arrives (`{ "data": ... }` is unwrapped, not nested), and several
 * payloads are composites with no single model behind them - the office row
 * carries its live counters, its open batch and its resolved services. A
 * resource per model cannot express those without a resource per endpoint,
 * which is a larger amount of near-identical code for the same result.
 *
 * NOTHING HERE ECHOES A PASSWORD
 * passwords are stored as clear text in this schema, so a naive
 * $user->toArray() would hand the credential straight back to the client.
 * user() is the only way an account leaves this class, and it names its
 * fields explicitly.
 */
final class ApiPresenter
{
    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    */

    /**
     * The account shape every authenticated endpoint answers with.
     *
     * Used for sign-in, GET /auth/me, the account list and the profile
     * update, so a client never has to learn two spellings of a user.
     *
     * @return array<string, mixed>
     */
    public static function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'student_id' => $user->student_id,
            'course' => $user->course,
            'block' => $user->block,
            'year_level' => $user->year_level,
            'phone' => $user->phone,
            'office_id' => $user->office_id,
            'is_active' => (bool) $user->is_active,
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public static function users(array $users): array
    {
        return array_values(array_filter(array_map(
            static fn (User $user): ?array => self::user($user),
            $users
        )));
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * A course, including the office it is served by.
     *
     * @return array<string, mixed>|null
     */
    public static function course(?Course $course): ?array
    {
        if (! $course) {
            return null;
        }

        return [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $course->name,
            'office_id' => $course->office_id,
            'is_active' => (bool) $course->is_active,
        ];
    }

    /**
     * @param  iterable<int, Course>  $courses
     * @return array<int, array<string, mixed>>
     */
    public static function courses(iterable $courses): array
    {
        $out = [];

        foreach ($courses as $course) {
            $out[] = self::course($course);
        }

        return $out;
    }

    /**
     * A counter, including its services and stations.
     *
     * `accepts_course` is resolved against the signed-in student here rather
     * than in the client, because a station's course list is a server rule.
     * The Dart model treats a null value as "not resolved" and falls back to
     * reading the list itself, which is what keeps an admin's unfiltered view
     * from silently inheriting one student's course.
     *
     * @return array<string, mixed>
     */
    public static function station(OfficeStation $station, ?string $viewerCourse = null): array
    {
        return [
            'id' => $station->id,
            'office_id' => $station->office_id,
            'name' => $station->name,
            'location' => $station->location,
            'course_codes' => $station->course_codes ?: [],
            'min_daily_students' => (int) $station->min_daily_students,
            'target_daily_students' => (int) $station->target_daily_students,
            'max_daily_slots' => (int) $station->max_daily_slots,
            'opening_time' => $station->opening_time,
            'closing_time' => $station->closing_time,
            'is_active' => (bool) $station->is_active,
            'accepts_course' => $viewerCourse === null
                ? null
                : $station->acceptsCourse($viewerCourse),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function service(OfficeService $service): array
    {
        return [
            'id' => $service->id,
            'office_id' => $service->office_id,
            'station_id' => $service->station_id,
            'name' => $service->name,
            'description' => $service->description,
            'requires_course' => (bool) $service->requires_course,
            'is_active' => (bool) $service->is_active,
            'station' => $service->relationLoaded('station') && $service->station
                ? self::station($service->station)
                : null,
        ];
    }

    /**
     * @param  iterable<int, OfficeService>  $services
     * @return array<int, array<string, mixed>>
     */
    public static function services(iterable $services): array
    {
        $out = [];

        foreach ($services as $service) {
            $out[] = self::service($service);
        }

        return $out;
    }

    /**
     * One batch of queue slots for an office on a given day.
     *
     * Named `open_batch`/`active_batch` on the office and `deployment` on a
     * ticket because those are the two different questions being asked: is
     * this batch still taking numbers, and is this ticket part of one.
     *
     * @return array<string, mixed>
     */
    public static function deployment(?QueueDeployment $deployment): ?array
    {
        if (! $deployment) {
            return null;
        }

        return [
            'id' => $deployment->id,
            'office_id' => $deployment->office_id,
            'batch_no' => (int) $deployment->batch_no,
            'status' => $deployment->status,
            'max_slots' => (int) $deployment->max_slots,
            'submitted_count' => $deployment->submittedCount(),
            'served_count' => $deployment->servedCount(),
            'opened_at' => optional($deployment->opened_at)->toIso8601String(),
            'closed_at' => optional($deployment->closed_at)->toIso8601String(),
            'completed_at' => optional($deployment->completed_at)->toIso8601String(),
        ];
    }

    /**
     * The slot counters a client renders as "AVAILABLE / TOTAL".
     *
     * Resolved here rather than in Dart so the phone is reading the same
     * arithmetic the counter uses, and so there is one definition of "how many
     * numbers can still be taken" instead of one per platform.
     *
     * An office with no deployed batch reports 0 available against the office's
     * slot limit, which is what puts it in the CLOSED state before an
     * administrator has deployed anything. Reporting the full limit instead
     * would tell a student an office is wide open when nothing can be issued
     * there yet.
     *
     * @return array<string, mixed>
     */
    public static function slotState(?QueueDeployment $batch, Office $office, bool $windowOpen = true): array
    {
        $limit = $office->slotLimit();
        $total = $batch ? (int) $batch->max_slots : $limit;
        $used = $batch ? $batch->submittedCount() : 0;
        $available = $batch ? max(0, $total - $used) : 0;

        $accepting = $windowOpen && $batch !== null && $batch->isOpen() && $available > 0;

        return [
            'slots_deployed' => $batch !== null,
            'total_slots' => $total > 0 ? $total : $limit,
            'used_slots' => $used,
            'available_slots' => $available,
            'queue_status' => $accepting ? 'open' : 'closed',
            // The batch status on its own ("full", "closed", "completed") is not
            // what the phone shows; the student cares whether a number can be
            // taken right now, which is also false outside office hours.
            'batch_status' => $batch?->status,
            'batch_no' => $batch ? (int) $batch->batch_no : null,
        ];
    }

    /**
     * An office with its live state resolved.
     *
     * The three booleans are the contract the client's guidance copy is built
     * on, so they are computed once, here:
     *
     *   window_open  inside the office's own opening/closing hours
     *   open_batch   a batch exists that is still accepting numbers
     *   accepting    both of the above, i.e. a ticket can actually be taken
     *
     * @param  array<int, OfficeService>  $services
     * @param  array<int, OfficeStation>  $stations
     * @return array<string, mixed>
     */
    public static function office(
        Office $office,
        ?QueueDeployment $openBatch = null,
        ?QueueDeployment $activeBatch = null,
        array $services = [],
        array $stations = [],
        ?string $viewerCourse = null,
    ): array {
        // An office the staff member has switched offline is closed for
        // ticketing even inside its opening hours, so offline folds into the
        // same `accepting`/`queue_status` the rest of the app reads. The hours
        // themselves are still reported via `window_open`.
        $windowOpen = QueueDeployment::officeWindowIsOpen($office) && $office->isOnline();
        $open = $openBatch ?: QueueDeployment::openFor($office);
        $active = $activeBatch ?: QueueDeployment::activeFor($office);

        $course = $office->relationLoaded('courses') && $office->courses->isNotEmpty()
            ? $office->courses->first()
            : null;

        // Resolved before the payload so `accepting` and the slot counters can
        // never disagree with each other: both come from the same batch.
        $slots = self::slotState($open, $office, $windowOpen);

        // The flat batch verdict follows the open batch while one exists;
        // otherwise it names the active (closed/full) batch so a staff-closed
        // batch reads as "closed" instead of a bare null.
        $slots['batch_status'] = $open?->status ?? $active?->status;

        return [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'description' => $office->description,
            'office_type' => $office->office_type,
            'location' => $office->location,
            'is_active' => (bool) $office->is_active,
            'status' => $office->status ?: Office::STATUS_OFFLINE,
            'opening_time' => $office->opening_time,
            'closing_time' => $office->closing_time,
            'max_slots' => $office->slotLimit(),
            'course' => self::course($course),
            'course_code' => $course?->code,
            'window_open' => $windowOpen,
            'accepting' => $slots['queue_status'] === 'open',
            ...$slots,
            'is_registrar_office' => str_starts_with((string) $office->code, 'REG-'),
            'open_batch' => self::deployment($open),
            'active_batch' => self::deployment($active),
            'waiting_count' => (int) $office->waitingTickets()
                ->whereDate('created_at', today())
                ->count(),
            'serving_count' => (int) $office->queueTickets()
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->count(),
            'services' => array_values(array_map(
                static fn (OfficeService $service): array => self::service($service),
                $services
            )),
            'stations' => array_values(array_map(
                static fn (OfficeStation $station): array => self::station($station, $viewerCourse),
                $stations
            )),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    */

    /**
     * A ticket with the four references the client renders around it.
     *
     * The references are reduced to id/name (plus location for a station)
     * rather than embedded whole, because the ticket card shows a label and a
     * column and nothing more. Embedding the full office would put a second,
     * subtly different copy of the same row in every ticket on the list.
     *
     * `has_evidence` is a boolean, not a path: the file itself lives outside
     * the web root and is streamed through an authenticated endpoint, so the
     * client only needs to know whether there is one to show.
     *
     * @return array<string, mixed>
     */
    public static function ticket(QueueTicket $ticket): array
    {
        $ticket->loadMissing([
            'office', 'service', 'station', 'course', 'deployment', 'servedByStaff', 'noShowByStaff',
        ]);

        return [
            'id' => $ticket->id,
            'queue_number' => $ticket->queue_number,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'purpose' => $ticket->purpose,
            'year_level' => $ticket->year_level,
            'has_evidence' => filled($ticket->priority_evidence),
            'office' => $ticket->office ? [
                'id' => $ticket->office->id,
                'name' => $ticket->office->name,
                'code' => $ticket->office->code,
                // The online/offline switch plus the office's own hours, so a
                // ticket receipt can state whether the counter is open at the
                // moment it is printed. Both are read off the already-loaded
                // office row, so listing 200 tickets costs no extra queries.
                'status' => $ticket->office->status ?: Office::STATUS_OFFLINE,
                'window_open' => QueueDeployment::officeWindowIsOpen($ticket->office),
                'accepting' => $ticket->office->isOnline()
                    && QueueDeployment::officeWindowIsOpen($ticket->office),
            ] : null,
            'service' => $ticket->service ? [
                'id' => $ticket->service->id,
                'name' => $ticket->service->name,
            ] : null,
            'station' => $ticket->station ? [
                'id' => $ticket->station->id,
                'name' => $ticket->station->name,
                'location' => $ticket->station->location,
            ] : null,
            'course' => self::course($ticket->course),
            'deployment' => self::deployment($ticket->deployment),
            'served_by' => $ticket->servedByStaff ? [
                'id' => $ticket->servedByStaff->id,
                'name' => $ticket->servedByStaff->name,
            ] : null,
            'called_at' => optional($ticket->called_at)->toIso8601String(),
            'call_duration_minutes' => $ticket->call_duration_minutes !== null
                ? (int) $ticket->call_duration_minutes
                : $ticket->effectiveCallDuration(),
            'expires_at' => optional($ticket->expires_at)->toIso8601String(),
            'remaining_seconds' => $ticket->remainingSeconds(),
            'is_expired' => $ticket->isExpired(),
            'is_no_show' => $ticket->status === QueueTicket::STATUS_NO_SHOW,
            'arrived_at' => optional($ticket->arrived_at)->toIso8601String(),
            'is_arrived' => $ticket->isArrived(),
            'no_show_at' => optional($ticket->no_show_at)->toIso8601String(),
            'no_show_by' => $ticket->noShowByStaff ? [
                'id' => $ticket->noShowByStaff->id,
                'name' => $ticket->noShowByStaff->name,
            ] : null,
            'recall_count' => (int) ($ticket->recall_count ?? 0),
            'last_recalled_at' => optional($ticket->last_recalled_at)->toIso8601String(),
            'cancelled_at' => optional($ticket->cancelled_at)->toIso8601String(),
            'cancel_reason' => $ticket->cancel_reason,
            'served_at' => optional($ticket->served_at)->toIso8601String(),
            'created_at' => optional($ticket->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<int, QueueTicket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    public static function tickets(iterable $tickets): array
    {
        $out = [];

        foreach ($tickets as $ticket) {
            $out[] = self::ticket($ticket);
        }

        return $out;
    }

    /**
     * Where a waiting ticket sits in its line, and how long that is.
     *
     * Counted in the same priority-then-oldest order the counter calls
     * numbers in, so the position shown to a student and the number the
     * member of staff at the window calls next can never disagree.
     *
     * @return array<string, mixed>
     */
    public static function progress(QueueTicket $ticket): array
    {
        $waitingMinutes = (int) Setting::get('default_waiting_time', 5);
        $waitingMinutes = $waitingMinutes > 0 ? $waitingMinutes : 5;

        $ahead = QueueTicket::where('office_id', $ticket->office_id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->whereDate('created_at', today())
            ->where('id', '<=', $ticket->id)
            ->get()
            ->sortBy([
                // Priority first: a PWD ticket is called ahead of any regular
                // one regardless of when it was issued, so a plain
                // row-count would overstate the wait for a PWD holder.
                fn (QueueTicket $a, QueueTicket $b): int => ($a->priority === QueueTicket::PRIORITY_PWD ? 0 : 1)
                    <=> ($b->priority === QueueTicket::PRIORITY_PWD ? 0 : 1),
                fn (QueueTicket $a, QueueTicket $b): int => $a->created_at <=> $b->created_at,
            ])
            ->search(fn (QueueTicket $row): bool => $row->id === $ticket->id);

        $position = $ahead === false ? 0 : $ahead + 1;
        $peopleAhead = max(0, $position - 1);

        $nowServing = QueueTicket::where('office_id', $ticket->office_id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->latest('called_at')
            ->first();

        return [
            'estimated_wait_minutes' => $peopleAhead * $waitingMinutes,
            'people_ahead' => $peopleAhead,
            'position' => $position,
            'minutes_per_ticket' => $waitingMinutes,
            'now_serving' => $nowServing ? [
                'id' => $nowServing->id,
                'queue_number' => $nowServing->queue_number,
                'called_at' => optional($nowServing->called_at)->toIso8601String(),
                'call_duration_minutes' => $nowServing->call_duration_minutes !== null
                    ? (int) $nowServing->call_duration_minutes
                    : QueueTicket::DEFAULT_CALL_DURATION_MINUTES,
                'expires_at' => optional($nowServing->expires_at)->toIso8601String(),
                'remaining_seconds' => $nowServing->remainingSeconds(),
                'is_expired' => $nowServing->isExpired(),
                'arrived_at' => optional($nowServing->arrived_at)->toIso8601String(),
                'is_arrived' => $nowServing->isArrived(),
                'recall_count' => (int) ($nowServing->recall_count ?? 0),
            ] : null,
            'waiting_count' => QueueTicket::where('office_id', $ticket->office_id)
                ->where('status', QueueTicket::STATUS_WAITING)
                ->whereDate('created_at', today())
                ->count(),
        ];
    }

    /**
     * Ticket counters, campus-wide or for one student.
     *
     * Scoped to today for the live three and to all time for `total`,
     * because "total" is a lifetime figure the dashboard labels as such
     * while "waiting"/"serving" are a live queue.
     *
     * @return array<string, int>
     */
    public static function counts(?User $student = null): array
    {
        $base = QueueTicket::query()
            ->when($student, fn ($q) => $q->where('user_id', $student->id));

        $today = (clone $base)->whereDate('created_at', today());

        $count = static fn ($query, string $status): int => (int) (clone $query)
            ->where('status', $status)
            ->count();

        return [
            'total' => (int) $base->count(),
            'waiting' => $count($today, QueueTicket::STATUS_WAITING),
            'serving' => $count($today, QueueTicket::STATUS_SERVING),
            'served' => $count($today, QueueTicket::STATUS_SERVED),
            'cancelled' => $count($today, QueueTicket::STATUS_CANCELLED),
            'no_show' => $count($today, QueueTicket::STATUS_NO_SHOW),
            'today' => (int) $today->count(),
        ];
    }

    /**
     * The "now serving" board for one office.
     *
     * @param  iterable<int, QueueTicket>  $recentlyServed
     * @return array<string, mixed>
     */
    public static function board(
        Office $office,
        ?QueueTicket $nowServing,
        int $servedCount,
        iterable $recentlyServed,
    ): array {
        return [
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ],
            'now_serving' => $nowServing ? [
                'id' => $nowServing->id,
                'ticket_id' => $nowServing->id,
                'queue_number' => $nowServing->queue_number,
                'status' => $nowServing->status,
                'called_at' => optional($nowServing->called_at)->toIso8601String(),
                'call_duration_minutes' => $nowServing->call_duration_minutes !== null
                    ? (int) $nowServing->call_duration_minutes
                    : QueueTicket::DEFAULT_CALL_DURATION_MINUTES,
                'expires_at' => optional($nowServing->expires_at)->toIso8601String(),
                'remaining_seconds' => $nowServing->remainingSeconds(),
                'is_expired' => $nowServing->isExpired(),
                'arrived_at' => optional($nowServing->arrived_at)->toIso8601String(),
                'is_arrived' => $nowServing->isArrived(),
                'recall_count' => (int) ($nowServing->recall_count ?? 0),
            ] : null,
            'waiting_count' => (int) $office->waitingTickets()
                ->whereDate('created_at', today())
                ->count(),
            'serving_count' => (int) $office->queueTickets()
                ->where('status', QueueTicket::STATUS_SERVING)
                ->whereDate('created_at', today())
                ->count(),
            'no_show_count' => (int) $office->queueTickets()
                ->where('status', QueueTicket::STATUS_NO_SHOW)
                ->whereDate('created_at', today())
                ->count(),
            'served_count' => $servedCount,
            'window_open' => QueueDeployment::officeWindowIsOpen($office),
            'recent_served' => self::servedNumbers($recentlyServed),
        ];
    }

    /**
     * The roll of numbers already served, for the counter's "recently called"
     * strip. Accepts a collection or a plain array.
     *
     * @param  iterable<int, QueueTicket>  $recentlyServed
     * @return array<int, array<string, mixed>>
     */
    private static function servedNumbers(iterable $recentlyServed): array
    {
        $out = [];

        foreach ($recentlyServed as $ticket) {
            $out[] = [
                'queue_number' => $ticket->queue_number,
                'served_at' => optional($ticket->served_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements & settings
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public static function announcement(Announcement $announcement): array
    {
        $announcement->loadMissing(['author', 'office']);

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'status' => $announcement->status,
            'is_active' => (bool) $announcement->is_active,
            'audience' => $announcement->audience ?? Announcement::AUDIENCE_BOTH,
            'author' => $announcement->author?->name,
            'office' => $announcement->office?->name,
            'created_at' => optional($announcement->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<int, Announcement>  $announcements
     * @return array<int, array<string, mixed>>
     */
    public static function announcements(iterable $announcements): array
    {
        $out = [];

        foreach ($announcements as $announcement) {
            $out[] = self::announcement($announcement);
        }

        return $out;
    }

    /**
     * The public settings allow-list.
     *
     * An allow-list rather than every row in `settings`, because that table
     * also holds session_timeout, lockout_duration, the last backup time and
     * the smtp-adjacent values an administrator changed. None of those belong
     * in a payload a phone holds in memory. `Setting::get` is called per key
     * against one table rather than loading it once, because this runs on the
     * bootstrap path of every cold start and the table is a handful of rows.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $text = static fn (string $key, string $fallback): string => (string) Setting::get($key, $fallback);
        $number = static fn (string $key, int $fallback): int => (int) Setting::get($key, $fallback);
        $flag = static fn (string $key): bool => (string) Setting::get($key, '0') === '1';

        return [
            'site_name' => $text('site_name', 'QueueFlow TMC'),
            'college_name' => $text('college_name', 'Trinidad Municipal College'),
            'system_description' => $text('system_description', ''),
            'contact_email' => $text('contact_email', ''),
            'contact_number' => $text('contact_number', ''),
            'default_waiting_time' => $number('default_waiting_time', 5),
            'default_opening_time' => $text('default_opening_time', '08:00'),
            'default_closing_time' => $text('default_closing_time', '15:00'),
            'operational_start_time' => $text('operational_start_time', '08:00'),
            'operational_end_time' => $text('operational_end_time', '15:00'),
            'max_active_tickets_per_student' => $number('max_active_tickets_per_student', 1),
            'max_slots_per_station' => $number('max_slots_per_station', 100),
            'minimum_students_per_station' => $number('minimum_students_per_station', 40),
            'target_students_per_station' => $number('target_students_per_station', 60),
            'supported_courses' => User::COURSES,
            'supported_year_levels' => User::YEAR_LEVELS,
            'maintenance_mode' => $flag('maintenance_mode'),
            'maintenance_message' => $text('maintenance_message', ''),
            'ticket_created_notification' => $flag('ticket_created_notification'),
            'queue_almost_ready_notification' => $flag('queue_almost_ready_notification'),
            'now_serving_notification' => $flag('now_serving_notification'),
            'ticket_completed_notification' => $flag('ticket_completed_notification'),
            'ticket_cancelled_notification' => $flag('ticket_cancelled_notification'),
            'announcement_notification' => $flag('announcement_notification'),
            'show_system_logo' => $flag('show_system_logo'),
            'primary_color' => $text('primary_color', '#073B91'),
            'secondary_color' => $text('secondary_color', '#1264D8'),
            'accent_color' => $text('accent_color', '#FFB300'),
        ];
    }
}