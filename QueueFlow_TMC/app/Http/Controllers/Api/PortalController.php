<?php

namespace App\Http\Controllers\Api;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Office;
use App\Models\OfficeService;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything a role shell needs before it draws anything.
 *
 * One `bootstrap` call per role rather than five round trips on cold start:
 * the student queue screen opens on a phone that may be on a congested campus
 * WiFi link, and five sequential requests to assemble one screen is five times
 * the chance of it half-loading. Each role still gets its own payload, because
 * what an administrator needs to see and what a student may see are genuinely
 * different documents - not the same document with fields removed.
 */
class PortalController extends ApiController
{
    /**
     * GET /api/v1/bootstrap
     *
     * Dispatched by role. Any of the three explicit paths below is also
     * registered, so a client that knows its role can skip the dispatch.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        return match ($this->account($request)->role) {
            User::ROLE_ADMIN => $this->adminOverview($request),
            User::ROLE_STAFF => $this->staffBootstrap($request),
            default => $this->studentBootstrap($request),
        };
    }

    /**
     * GET /api/v1/student/bootstrap
     *
     * The student shell: their own account, the catalogue they may queue
     * against, and the settings their screens quote. Deliberately excludes
     * other students, all accounts, and the batch controls.
     */
    public function studentBootstrap(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_STUDENT);

        return $this->json([
            'student' => ApiPresenter::user($user),
            'settings' => ApiPresenter::settings(),
            'announcements' => ApiPresenter::announcements($this->publishedAnnouncements(Announcement::AUDIENCE_USER)),
            'courses' => ApiPresenter::courses(
                Course::where('is_active', true)->orderBy('code')->get()
            ),
            'offices' => $this->officePayloads($this->visibleOffices($user), $user->course),
        ]);
    }

    /**
     * GET /api/v1/staff/bootstrap
     *
     * The counter shell. Carries the one office this person is assigned to
     * rather than all of them: a staff member has no way to act on an office
     * they are not assigned to (see guardOffice), so listing the rest would
     * only offer choices that answer 403 when used.
     */
    public function staffBootstrap(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_STAFF);

        $office = $this->staffOffice($user);

        if (! $office) {
            // A reachable but unassigned account is an administrative problem,
            // not a bug in the client, so it gets a 403 that says so instead
            // of an empty shell the operator cannot explain.
            return $this->fail(
                'Your staff account is not assigned to an active operational office. Please contact the registrar.',
                403
            );
        }

        return $this->json([
            'staff' => ApiPresenter::user($user),
            'settings' => ApiPresenter::settings(),
            'announcements' => ApiPresenter::announcements($this->publishedAnnouncements(Announcement::AUDIENCE_STAFF)),
            'offices' => $this->officePayloads(collect([$office])),
        ]);
    }

    /**
     * GET /api/v1/admin/overview
     *
     * The admin shell: every office, every account and the campus counters.
     */
    public function adminOverview(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN);

        return $this->json([
            'counts' => ApiPresenter::counts(),
            'settings' => ApiPresenter::settings(),
            'announcements' => ApiPresenter::announcements($this->publishedAnnouncements()),
            'offices' => $this->officePayloads($this->visibleOffices($user)),
            'office_count' => Office::whereIn('code', Office::OPERATIONAL_CODES)->count(),
            'active_office_count' => Office::whereIn('code', Office::OPERATIONAL_CODES)->where('is_active', true)->count(),
            'station_count' => \App\Models\OfficeStation::where('is_active', true)->count(),
            'student_count' => User::where('role', User::ROLE_STUDENT)->count(),
            'staff_count' => User::where('role', User::ROLE_STAFF)->count(),
        ]);
    }

    /**
     * GET /api/v1/offices
     */
    public function offices(Request $request): JsonResponse
    {
        $user = $this->account($request);

        // A staff member asking for the catalogue gets their own office only,
        // for the same reason staffBootstrap does.
        if ($user->isStaff()) {
            $offices = collect(array_filter([$this->staffOffice($user)]));
        } else {
            $offices = $this->visibleOffices($user);
        }

        return $this->json([
            'offices' => $this->officePayloads($offices, $user->course),
        ]);
    }

    /**
     * GET /api/v1/offices/{office}/live
     *
     * The board a waiting student polls. Reads only, and the cheapest of the
     * endpoints: no nested services or stations, because the live board draws
     * a number, not a catalogue.
     */
    public function live(Request $request, Office $office): JsonResponse
    {
        $user = $this->account($request);

        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT);

        // Re-resolved through operationalOffice() rather than trusted from the
        // route binding: the route only proves the id exists, and a student
        // polling a board should not be able to read counters for a retired
        // or deactivated office by guessing its id.
        $office = $this->operationalOffice($office->id);

        $nowServing = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->whereDate('created_at', today())
            ->latest('called_at')
            ->first();

        $servedCount = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVED)
            ->whereDate('created_at', today())
            ->count();

        return $this->json(ApiPresenter::board(
            $office,
            $nowServing,
            $servedCount,
            QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVED)
                ->whereDate('created_at', today())
                ->latest('served_at')
                ->limit(10)
                ->get()
        ));
    }

    /**
     * GET /api/v1/courses
     */
    public function courses(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT);

        return $this->json([
            'courses' => ApiPresenter::courses(
                Course::where('is_active', true)->orderBy('code')->get()
            ),
        ]);
    }

    /**
     * GET /api/v1/registrar/courses
     *
     * The Registrar course picker a student chooses from before taking a
     * number.
     *
     * WHY THIS IS NOT JUST /offices
     * `visibleOffices()` deliberately narrows the office catalogue to the
     * signed-in student's own academic college, because that catalogue is also
     * the set of offices POST /tickets will quietly accept. A student needs to
     * *see* that the Registrar runs seven queues before picking one, but the
     * catalogue must not become a menu of offices the server would then let
     * them queue at.
     *
     * So this is a separate read-only projection. It answers "which Registrar
     * queues exist and how many numbers does each have left" and changes
     * nothing about what a ticket may be issued against: the station/course
     * check in QueueTicketCreator still runs against the signed-in student's
     * own course, so choosing another college's queue here is refused at
     * ticket creation rather than silently honoured.
     *
     * Nothing is written and no office id from this response is treated as
     * authority to queue.
     */
    public function registrarCourses(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT);

        $offices = Office::assignable()
            ->where('code', 'like', 'REG-%')
            ->with('courses')
            ->orderBy('name')
            ->get();

        // The day's deployments for these offices, newest first, so the row that
        // is chosen below is the CURRENT one. Ordering by id ascending (or just
        // taking the first) would report the morning's finished batch after the
        // afternoon one had opened, and the card would say CLOSED while numbers
        // were being issued.
        $batches = QueueDeployment::whereIn('office_id', $offices->pluck('id'))
            ->whereDate('created_at', today())
            ->orderByDesc('id')
            ->get()
            ->groupBy('office_id');

        $enrolled = $user->course ? strtoupper($user->course) : null;

        $services = OfficeService::whereIn('office_id', $offices->pluck('id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('office_id');

        // Live queue counters per Registrar office, scoped to today with the
        // same status rules as the per-office live board: waiting = still in
        // line, serving = at the counter, served = completed. Cancelled and
        // no-show rows are excluded from all three, and each ticket belongs
        // to exactly one office_id so summing courses never double-counts.
        $ticketCounts = QueueTicket::whereIn('office_id', $offices->pluck('id'))
            ->whereDate('created_at', today())
            ->get(['office_id', 'status'])
            ->groupBy('office_id');

        $courses = $offices->map(function (Office $office) use ($batches, $services, $ticketCounts, $enrolled): array {
            $course = $office->courses->first();
            $today = $batches->get($office->id, collect());

            // Prefer the batch that is actually issuing numbers. Only when there
            // is none does the newest one win, so a student still sees "how many
            // were left" for a batch that has since been closed rather than a
            // bare zero with no explanation.
            $batch = $today->firstWhere('status', QueueDeployment::STATUS_OPEN) ?? $today->first();

            // Resolved from the office's own hours, not assumed: a course queue
            // outside opening hours cannot issue, and this endpoint has to agree
            // with the office payload or the same queue reads two different ways
            // on two screens. An offline Registrar folds into the same
            // `accepting`/`queue_status`, so the course picker agrees with the
            // main office list that the Registrar is not taking numbers.
            $slots = ApiPresenter::slotState(
                $batch,
                $office,
                QueueDeployment::officeWindowIsOpen($office) && $office->isOnline()
            );

            return [
                'course' => ApiPresenter::course($course),
                'course_code' => $course?->code,
                'office_id' => $office->id,
                'office_name' => $office->name,
                'status' => $office->status ?: Office::STATUS_OFFLINE,
                ...$slots,
                'accepting' => $slots['queue_status'] === 'open',
                // Today's queue position for this course queue alone. Each
                // Registrar course is its own office row, so these counts are
                // independent per course; the campus total sums them.
                'waiting_count' => $ticketCounts->get($office->id, collect())
                    ->where('status', QueueTicket::STATUS_WAITING)->count(),
                'serving_count' => $ticketCounts->get($office->id, collect())
                    ->where('status', QueueTicket::STATUS_SERVING)->count(),
                'served_count' => $ticketCounts->get($office->id, collect())
                    ->where('status', QueueTicket::STATUS_SERVED)->count(),
                'is_enrolled_course' => $course !== null
                    && $enrolled !== null
                    && strtoupper((string) $course->code) === $enrolled,
                // The services that office offers, so the client can pick one
                // without a second call. Without them the phone can list seven
                // course queues but can only complete the request for the
                // student's own college, which is its catalogue row.
                'services' => ApiPresenter::services($services->get($office->id, collect())),
            ];
        })->values();

        return $this->json([
            'registrar_courses' => $courses,
            'totals' => [
                // The aggregate is what the single Registrar card shows. Each
                // course runs its own 100-slot batch, so the honest total is the
                // sum across all seven rather than any one of them.
                'total_slots' => (int) $courses->sum('total_slots'),
                'available_slots' => (int) $courses->sum('available_slots'),
                'course_count' => $courses->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/announcements
     */
    public function announcementIndex(Request $request): JsonResponse
    {
        $user = $this->account($request);
        $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT);

        $audience = match ($user->role) {
            User::ROLE_STUDENT => Announcement::AUDIENCE_USER,
            User::ROLE_STAFF => Announcement::AUDIENCE_STAFF,
            default => null,
        };

        return $this->json([
            'announcements' => ApiPresenter::announcements($this->publishedAnnouncements($audience)),
        ]);
    }

    /**
     * GET /api/v1/settings
     *
     * Read-only. The admin screen that writes settings is still the Blade
     * portal; exposing a write path here would be a second way to change
     * campus-wide behaviour, with none of the confirmation the portal has.
     */
    public function settings(Request $request): JsonResponse
    {
        $this->guardRoles($this->account($request), User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_STUDENT);

        return $this->json(['settings' => ApiPresenter::settings()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The offices this account may see, with their catalogue eager-loaded.
     *
     * @return \Illuminate\Support\Collection<int, Office>
     */
    private function visibleOffices(User $user)
    {
        // Official offices plus any active custom office the admin added, so
        // a newly created office reaches the app without a code change.
        $query = Office::assignable()
            ->orderBy('name');

        // Students only ever queue against their own academic college. This
        // is a server-side filter on purpose: hiding an office in the client
        // would leave POST /tickets accepting any office id at all.
        if ($user->isStudent() && filled($user->course)) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('code')
                    ->orWhere('code', 'not like', 'REG-%')
                    ->orWhereIn('id', function ($sub) use ($user) {
                        $sub->select('office_id')
                            ->from('courses')
                            ->where('code', $user->course);
                    });
            });
        }

        return $query->with(['services.station', 'stations', 'courses'])->get();
    }

    /**
     * Presenter payloads for a set of offices.
     *
     * The open and active batches are fetched in two queries for the whole
     * set rather than two per office: the list endpoint returns a dozen
     * offices, and a dozen offices times two point queries is a visible pause
     * on the phone's first screen.
     *
     * @param  \Illuminate\Support\Collection<int, Office>  $offices
     * @return array<int, array<string, mixed>>
     */
    private function officePayloads($offices, ?string $viewerCourse = null): array
    {
        $ids = $offices->pluck('id');

        $batches = QueueDeployment::whereIn('office_id', $ids)
            ->whereDate('created_at', today())
            ->orderBy('id')
            ->get()
            ->groupBy('office_id');

        $served = QueueTicket::whereIn('office_id', $ids)
            ->whereDate('created_at', today())
            ->get(['office_id', 'status'])
            ->groupBy('office_id');

        return $offices->map(function (Office $office) use ($batches, $served, $viewerCourse) {
            $officeBatches = $batches->get($office->id, collect());
            $officeTickets = $served->get($office->id, collect());

            $open = $officeBatches->firstWhere('status', QueueDeployment::STATUS_OPEN);
            // Newest non-completed batch, matching activeFor(): after a close
            // + redeploy the office carries both the closed row and the fresh
            // open one, and the phone must read the fresh one.
            $active = $officeBatches
                ->filter(fn (QueueDeployment $d): bool => $d->status !== QueueDeployment::STATUS_COMPLETED)
                ->last();

            // Overridden rather than recomputed inside the presenter: the
            // counts came from the grouped queries above, and asking the
            // presenter to count again would throw that work away.
            $payload = ApiPresenter::office(
                $office,
                $open,
                $active,
                $office->services->all(),
                $office->stations->all(),
                $viewerCourse
            );

            $payload['waiting_count'] = $officeTickets
                ->where('status', QueueTicket::STATUS_WAITING)->count();
            $payload['serving_count'] = $officeTickets
                ->where('status', QueueTicket::STATUS_SERVING)->count();

            return $payload;
        })->values()->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Announcement>
     */
    private function publishedAnnouncements(?string $audience = null)
    {
        return Announcement::publicFor($audience)
            ->with(['author', 'office'])
            ->latest('created_at')
            ->limit(20)
            ->get();
    }
}