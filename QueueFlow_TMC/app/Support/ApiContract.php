<?php

namespace App\Support;

/**
 * The published contract for QueueFlow's API surface.
 *
 * This is the single description of what the API offers. The X-Api-Version
 * response header and GET /api/v1 both read from it, so a client and a
 * deployment cannot disagree about the version they are speaking.
 *
 * The history matters here. The student and staff mobile APIs and the admin
 * JSON panel were all removed at one point, leaving password recovery as the
 * only JSON surface, and this class said so. The bearer-token API consumed by
 * the Flutter Web client has since been rebuilt on top of the same models and
 * services, so the surface is documented again - but the shape below is
 * deliberately the union of two spellings: the versioned /api/v1/... paths
 * that are the real contract, and the short unversioned aliases that some
 * clients are written against. The aliases are the same controller methods,
 * so publishing both costs nothing and means a client written to either
 * spelling finds its endpoints listed.
 */
final class ApiContract
{
    public const VERSION = 'v1';

    /**
     * The machine-readable description of this version.
     *
     * `auth` is the only section that appears twice: a client reading this
     * needs to know that password recovery is reachable at both the versioned
     * path and the legacy /api/student/... pair an older deployed build calls,
     * so an un-updated phone can still recover a password.
     */
    public static function discovery(): array
    {
        return [
            'api' => 'queueflow-tmc',
            'version' => self::VERSION,
            'status' => 'ok',
            'time' => now()->toIso8601String(),
            'auth' => 'bearer',
            'header' => 'Authorization: Bearer <token>',
            'note' => 'Passwords in this schema are stored as clear text. Do not log or share a token; sign out revokes only the current one.',
            'endpoints' => [
                'discovery' => [url('/api/v1')],

                // Liveness probe. Unauthenticated and dependency-free, so a
                // client can ask "is QueueFlow actually serving?" separately
                // from "does this phone have a network at all?".
                'health' => [url('/api/v1/health'), url('/api/health')],

                'login' => [url('/api/v1/auth/login'), url('/api/login')],
                'logout' => [url('/api/v1/auth/logout'), url('/api/logout')],
                'me' => [url('/api/v1/auth/me'), url('/api/user')],
                'update_profile' => [url('/api/v1/auth/me'), url('/api/user')],
                'change_password' => [url('/api/v1/auth/password'), url('/api/password')],

                // Password recovery predates this client and keeps both the
                // versioned path and the legacy /api/student/... shape.
                'forgot_password' => [
                    url('/api/v1/auth/forgot-password'),
                    url('/api/student/forgot-password'),
                ],
                'reset_password' => [
                    url('/api/v1/auth/reset-password'),
                    url('/api/student/reset-password'),
                ],

                // Dispatched on the account's role; the explicit paths are
                // registered too, so a client that knows its role can skip it.
                'bootstrap' => [url('/api/v1/bootstrap')],
                'student_bootstrap' => [url('/api/v1/student/bootstrap')],
                'staff_bootstrap' => [url('/api/v1/staff/bootstrap')],
                'admin_overview' => [url('/api/v1/admin/overview')],

                'offices' => [url('/api/v1/offices'), url('/api/offices')],
                'office_live_board' => [url('/api/v1/offices/{office}/live'), url('/api/offices/{office}/live')],
                'courses' => [url('/api/v1/courses')],
                'registrar_courses' => [url('/api/v1/registrar/courses')],
                'announcements' => [url('/api/v1/announcements')],
                'settings' => [url('/api/v1/settings')],

                'tickets' => [url('/api/v1/tickets'), url('/api/tickets')],
                'take_ticket' => [url('/api/v1/tickets'), url('/api/queues')],
                'show_ticket' => [url('/api/v1/tickets/{ticket}'), url('/api/tickets/{ticket}')],
                'cancel_ticket' => [url('/api/v1/tickets/{ticket}/cancel'), url('/api/queues/{ticket}')],
                'ticket_evidence' => [url('/api/v1/tickets/{ticket}/evidence')],

                'queues' => [url('/api/v1/queues'), url('/api/queues')],
                'office_queue' => [url('/api/v1/queues/{office}'), url('/api/queues/{office}')],
                'call_next' => [url('/api/v1/queues/{office}/call-next')],
                'serve_ticket' => [url('/api/v1/queues/{office}/tickets/{ticket}/serve')],
                'arrive_ticket' => [url('/api/v1/queues/{office}/tickets/{ticket}/arrive')],
                'recall_ticket' => [url('/api/v1/queues/{office}/tickets/{ticket}/recall')],
                'no_show' => [url('/api/v1/queues/{office}/tickets/{ticket}/no-show')],
                'queue_timeout' => [url('/api/v1/queues/{office}/timeout')],
                'expire_sweep' => [url('/api/v1/queues/{office}/expire')],
                'update_ticket' => [url('/api/v1/queues/{office}/tickets/{ticket}'), url('/api/queues/{ticket}')],

                'deploy_batch' => [url('/api/v1/offices/{office}/deploy')],
                'close_batch' => [url('/api/v1/offices/{office}/close-batch')],
                'complete_batch' => [url('/api/v1/offices/{office}/complete-batch')],
                'start_batch' => [url('/api/v1/offices/{office}/start-batch')],

                'accounts' => [url('/api/v1/accounts'), url('/api/users')],
                'create_account' => [url('/api/v1/accounts'), url('/api/users')],
                'update_account' => [url('/api/v1/accounts/{user}'), url('/api/users/{user}')],
                'deactivate_account' => [url('/api/v1/accounts/{user}'), url('/api/users/{user}')],
                'restore_account' => [url('/api/v1/accounts/{user}/restore')],
                'account_office_options' => [url('/api/v1/accounts/options/offices')],
            ],
        ];
    }
}
