<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\OfficeService;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use App\Support\ValidatesImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared ticket-creation rules for the admin, API and student-facing flows.
 *
 * Enforces:
 *  - Office activation
 *  - Acceptance window (opening/closing time)
 *  - 100-slot per-batch limit
 *  - No duplicate queue numbers within a batch
 *  - PWD priority + mandatory evidence upload
 *  - Optional per-student active-ticket limit (atomic, race-safe)
 */
class QueueTicketCreator
{
    use ValidatesImageUpload;

    /**
     * Maximum size for a PWD evidence upload.
     */
    public const MAX_EVIDENCE_SIZE = 2048; // KB (2 MB)

    /**
     * The sentence shared by every flow that refuses a ticket for an office
     * the assigned staff member has marked offline.
     */
    public const OFFLINE_MESSAGE = 'This office is currently offline and is not accepting queue tickets.';

    /**
     * Validate + persist a ticket.
     *
     * @param  array  $data  office_id, user_id (nullable), purpose (nullable),
     *                       course_id (nullable), priority, priority_evidence (UploadedFile|null),
     *                       enforce_student_limit (bool: atomically refuse when
     *                       the owner already holds the maximum active tickets)
     * @param  bool   $requiredEvidenceRequired  force evidence upload whenever PWD is selected
     * @return array{ticket: QueueTicket, office: Office, message: string}
     *
     * @throws ValidationException
     */
    public function create(array $data, bool $evidenceRequired = true): array
    {
        $office = Office::whereKey($data['office_id'])
            ->where('is_active', true)
            ->first();

        if (! $office) {
            throw ValidationException::withMessages([
                'office_id' => 'The selected office is not available.',
            ]);
        }

        $this->guardOfficeOnline($office);

        $service = OfficeService::whereKey($data['service_id'] ?? null)
            ->where('office_id', $office->id)
            ->where('is_active', true)
            ->with('station')
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => 'Please select a valid service for the selected office.',
            ]);
        }

        if ($service->requires_course && blank($data['student_course'] ?? null)) {
            throw ValidationException::withMessages([
                'course_id' => 'This service requires a course selection.',
            ]);
        }

        $station = $service->station;
        if ($station && (! $station->is_active || ! $station->acceptsCourse($data['student_course'] ?? null))) {
            throw ValidationException::withMessages([
                'service_id' => 'The selected service/station is not available for your course.',
            ]);
        }

        $priority = $data['priority'] ?? QueueTicket::PRIORITY_REGULAR;
        $priority = in_array($priority, QueueTicket::PRIORITIES, true)
            ? $priority
            : QueueTicket::PRIORITY_REGULAR;

        $course = null;
        if (filled($data['course_id'] ?? null)) {
            // A student's course belongs to its academic college, but students
            // may legitimately take course-aware services at shared offices
            // such as the Registrar, Library, Clinic, Scholarship, NSTP, and SASO.
            $course = Course::whereKey($data['course_id'])
                ->where('is_active', true)
                ->first();

            if (! $course) {
                throw ValidationException::withMessages([
                    'course_id' => 'The selected course is not available.',
                ]);
            }
        }

        $this->guardOfficeAcceptingOffers($office);

        if ($priority === QueueTicket::PRIORITY_PWD && $evidenceRequired) {
            $evidence = $data['priority_evidence'] ?? null;

            if (! $evidence instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'priority_evidence' => 'PWD tickets require an uploaded evidence image.',
                ]);
            }

            $evidencePath = $this->storeEvidence($evidence);
        } else {
            $evidencePath = null;
        }

        $result = DB::transaction(function () use ($office, $service, $station, $course, $priority, $evidencePath, $data) {
            $deployment = QueueDeployment::openableFor($office);

            if (! $deployment) {
                return ['error' => 'closed'];
            }

            /*
            | Hold the batch row for the rest of the transaction.
            |
            | nextNumberForDeployment() works by reading the newest ticket in the
            | batch and adding one, so without a lock two students submitting at
            | the same moment both read the same row and both compute the same
            | number. Locking here serialises them: the second request waits, then
            | re-reads and sees the first one's ticket.
            |
            | Re-fetched with lockForUpdate() rather than locking the row inside
            | openableFor(), because openableFor() may have just INSERTed this
            | batch - there is nothing to lock on a row that did not exist when
            | the transaction opened, and re-selecting it by primary key gets
            | the lock the first read could not take.
            |
            | The unique index on (deployment_id, queue_number) added in
            | 2026_10_05_000000_unique_queue_number_per_deployment is the
            | backstop for the case this does not cover; both are wanted.
            */
            $deployment = QueueDeployment::whereKey($deployment->id)
                ->lockForUpdate()
                ->first() ?? $deployment;

            /*
            | Atomic backstop for the per-student active-ticket limit.
            |
            | The controllers pre-check this before calling here, but a
            | pre-check followed by a separate write is a race: two requests
            | from the same student arriving together both pass the check and
            | both issue. This count runs INSIDE the creation transaction with
            | the owner's active rows locked, so the second request waits,
            | then sees the first one's ticket and is refused. No slot is
            | consumed: the number is allocated after this check.
            |
            | Opt-in via `enforce_student_limit` because staff and admin
            | counter flows legitimately issue on someone's behalf and must
            | not be capped.
            */
            if (!empty($data['enforce_student_limit'])) {
                $this->guardStudentLimitLocked((int) ($data['user_id'] ?? 0));
            }

            $number = QueueTicket::nextNumberForDeployment($deployment);

            if (! $number) {
                // No more slots on this batch.
                if ($deployment->isOpen()) {
                    $deployment->update([
                        'status' => QueueDeployment::STATUS_FULL,
                        'closed_at' => $deployment->closed_at ?? now(),
                    ]);
                }

                return ['error' => 'full'];
            }

            return [
                'error' => null,
                'ticket' => QueueTicket::create([
                    'queue_number' => $number,
                    'office_id' => $office->id,
                    'service_id' => $service->id,
                    'station_id' => $station?->id,
                    'course_id' => $course?->id,
                    'year_level' => $data['year_level'] ?? null,
                    'priority' => $priority,
                    'priority_evidence' => $evidencePath,
                    'deployment_id' => $deployment->id,
                    'user_id' => $data['user_id'] ?? null,
                    'purpose' => $data['purpose'] ?? null,
                    'status' => QueueTicket::STATUS_WAITING,
                ]),
            ];
        });

        if ($result['error']) {
            throw ValidationException::withMessages([
                'office_id' => 'Queue is currently closed. The maximum 100 slots have been reached or the submission period has ended.',
            ]);
        }

        $ticket = $result['ticket'];

        return [
            'ticket' => $ticket,
            'office' => $office,
            'message' => "Queue {$ticket->queue_number} generated successfully.",
        ];
    }

    /**
     * Reject ticket creation when the office's staff member has switched it
     * offline, before any other check runs.
     *
     * This is the authoritative gate for the online/offline switch: every flow
     * that issues a ticket - the admin console, the student web form and the
     * REST API - goes through create(), so refusing here means an offline
     * office cannot be reached by any route.
     *
     * @throws ValidationException
     */
    protected function guardOfficeOnline(Office $office): void
    {
        if ($office->isOnline()) {
            return;
        }

        throw ValidationException::withMessages([
            'office_id' => self::OFFLINE_MESSAGE,
        ]);
    }

    /**
     * Refuse creation when the owner already holds the maximum active
     * tickets. Runs inside the creation transaction with the owner's active
     * rows locked — the atomic backstop behind the controllers' fast
     * pre-checks. Same statuses, same setting, same wording, so every door
     * refuses for the same reason in the same words.
     *
     * @throws ValidationException
     */
    protected function guardStudentLimitLocked(int $ownerId): void
    {
        if ($ownerId <= 0) {
            return;
        }

        $maxActive = (int) Setting::get('max_active_tickets_per_student', 1);

        if ($maxActive < 1) {
            return;
        }

        $activeCount = QueueTicket::where('user_id', $ownerId)
            ->whereIn('status', [
                QueueTicket::STATUS_WAITING,
                QueueTicket::STATUS_SERVING,
            ])
            ->whereDate('created_at', today())
            ->lockForUpdate()
            ->count();

        if ($activeCount >= $maxActive) {
            throw ValidationException::withMessages([
                'office_id' => $maxActive === 1
                    ? 'You already have an active queue ticket. Cancel it before taking another.'
                    : "You already have the maximum of {$maxActive} active tickets today.",
            ]);
        }
    }

    /**
     * Reject ticket creation when the office batch is full or the submission
     * window has ended.
     *
     * @throws ValidationException
     */
    protected function guardOfficeAcceptingOffers(Office $office): void
    {
        $open = QueueDeployment::openFor($office);

        // Still open: allow the caller to continue.
        if ($open) {
            return;
        }

        // No open deployment: is the office outside its acceptance window?
        $active = QueueDeployment::activeFor($office);

        if ($active && $active->isReadyForService()) {
            throw ValidationException::withMessages([
                'office_id' => 'Queue is currently closed. The maximum 100 slots have been reached or the submission period has ended.',
            ]);
        }

        if (! QueueDeployment::officeWindowIsOpen($office)) {
            throw ValidationException::withMessages([
                'office_id' => 'Queue is currently closed. The maximum 100 slots have been reached or the submission period has ended.',
            ]);
        }
    }

    /**
     * Validate and store a PWD evidence image using a safe unique filename.
     *
     * Files live under storage/app/pwd_evidence (a non-public path) and are
     * only ever served through an authenticated admin/staff route.
     *
     * @throws ValidationException
     */
    protected function storeEvidence(UploadedFile $file): string
    {
        $safeExtension = $this->guardImageUpload(
            $file,
            'priority_evidence',
            'PWD evidence image',
            self::MAX_EVIDENCE_SIZE
        );

        $name = 'pwd_' . Str::random(32) . '.' . $safeExtension;

        $path = $file->storeAs('pwd_evidence', $name);

        return $path;
    }

    /**
     * Publish-safe URL for an evidence path, or null when missing.
     */
    public static function evidenceExists(?string $path): bool
    {
        return filled($path) && Storage::disk('local')->exists($path);
    }
}