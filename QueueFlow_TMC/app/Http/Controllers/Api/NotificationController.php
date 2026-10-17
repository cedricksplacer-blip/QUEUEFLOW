<?php

namespace App\Http\Controllers\Api;

use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cancellation alerts over the bearer-token API.
 *
 * Staff receive only their assigned office's alerts; admins receive every
 * office (optionally narrowed with ?office_id=). Same rows the Blade bell
 * polls, so the two front doors can never disagree.
 */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->account($request);

        $query = StaffNotification::with(['office', 'ticket'])
            ->cancelled()
            ->recent();

        if ($user->isStaff()) {
            $office = $this->staffOffice($user);

            if (! $office) {
                return $this->fail('Your staff account is not assigned to an active operational office.', 403);
            }

            $query->forOffice($office->id);
        } elseif ($user->isAdmin()) {
            if ($request->filled('office_id')) {
                $query->where('office_id', $request->integer('office_id'));
            }
        } else {
            $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        }

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        $notifications = $query->limit(100)->get();

        $countQuery = StaffNotification::cancelled()->unread();

        if ($user->isStaff()) {
            $countQuery->forOffice($this->staffOffice($user)->id);
        } elseif ($request->filled('office_id')) {
            $countQuery->where('office_id', $request->integer('office_id'));
        }

        return $this->json([
            'notifications' => $notifications->map->toPanelArray()->values(),
            'unread_count' => $countQuery->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->account($request);

        $query = StaffNotification::cancelled()->unread();

        if ($user->isStaff()) {
            $office = $this->staffOffice($user);

            if (! $office) {
                return $this->fail('Your staff account is not assigned to an active operational office.', 403);
            }

            $query->forOffice($office->id);
        } elseif ($user->isAdmin()) {
            if ($request->filled('office_id')) {
                $query->where('office_id', $request->integer('office_id'));
            }
        } else {
            $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        }

        return $this->json(['unread_count' => $query->count()]);
    }

    public function markRead(Request $request, StaffNotification $notification): JsonResponse
    {
        $user = $this->account($request);

        if ($user->isStaff()) {
            $office = $this->staffOffice($user);

            if (! $office || $notification->office_id !== $office->id) {
                return $this->denied('You are not assigned to that office.');
            }
        } else {
            $this->guardRoles($user, User::ROLE_ADMIN, User::ROLE_STAFF);
        }

        $notification->markAsRead($user);

        return $this->json([
            'message' => 'Notification marked as read.',
            'notification' => $notification->fresh()->toPanelArray(),
        ]);
    }
}
