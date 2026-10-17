<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\StaffNotification;

/**
 * Sidebar badge counts for the Blade consoles.
 *
 * Every number here is a real COUNT query against existing tables — no
 * invented tasks, no arbitrary figures. A menu with no meaningful countable
 * event gets no badge (callers simply omit the key), which is why e.g.
 * Reports and Settings never appear in these arrays.
 *
 * Counts are memoized per request so the layout, the header and the polling
 * endpoint each pay for them at most once.
 */
final class SidebarBadges
{
    /** @var array<string, array<string, int>> */
    private static array $memo = [];

    /**
     * Display form of a count: hidden at zero by the caller, `99+` above 99.
     */
    public static function fmt(int $count): string
    {
        return $count > 99 ? '99+' : (string) $count;
    }

    /**
     * System-wide counts for the administrator sidebar.
     *
     * - waiting_today: today's waiting tickets across all offices.
     * - alerts_unread: unread cancellation alerts across all offices.
     * - announcements_pending: announcements awaiting admin review.
     * - offices_offline: operational offices not currently online.
     *
     * @return array<string, int>
     */
    public static function admin(): array
    {
        return self::remember('admin', function (): array {
            return [
                'waiting_today' => QueueTicket::where('status', QueueTicket::STATUS_WAITING)
                    ->whereDate('created_at', today())
                    ->count(),
                'alerts_unread' => StaffNotification::cancelled()
                    ->unread()
                    ->count(),
                'announcements_pending' => Announcement::where('status', Announcement::STATUS_PENDING)
                    ->count(),
                'offices_offline' => Office::whereIn('code', Office::OPERATIONAL_CODES)
                    ->where('status', '!=', Office::STATUS_ONLINE)
                    ->count(),
            ];
        });
    }

    /**
     * Office-scoped counts for one staff sidebar.
     *
     * - waiting_today: today's waiting tickets at this office.
     * - alerts_unread: unread cancellation alerts at this office.
     * - pwd_waiting: today's waiting PWD-priority tickets at this office.
     *
     * @return array<string, int>
     */
    public static function staff(int $officeId): array
    {
        return self::remember('staff:'.$officeId, function () use ($officeId): array {
            $today = today();

            return [
                'waiting_today' => QueueTicket::where('office_id', $officeId)
                    ->where('status', QueueTicket::STATUS_WAITING)
                    ->whereDate('created_at', $today)
                    ->count(),
                'alerts_unread' => StaffNotification::forOffice($officeId)
                    ->cancelled()
                    ->unread()
                    ->count(),
                'pwd_waiting' => QueueTicket::where('office_id', $officeId)
                    ->where('status', QueueTicket::STATUS_WAITING)
                    ->where('priority', QueueTicket::PRIORITY_PWD)
                    ->whereDate('created_at', $today)
                    ->count(),
            ];
        });
    }

    /** Clear the per-request memo (tests). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @param callable(): array<string, int> $compute
     * @return array<string, int>
     */
    private static function remember(string $key, callable $compute): array
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $compute();
        }

        return self::$memo[$key];
    }
}
