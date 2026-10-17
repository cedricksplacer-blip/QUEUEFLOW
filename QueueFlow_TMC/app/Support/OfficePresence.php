<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-driven office presence.
 *
 * An office is ONLINE while at least one of its assigned staff members is
 * signed in, and OFFLINE otherwise. Presence is tracked with a cache key
 * per staff member whose TTL mirrors the session lifetime, so an expired
 * session (no request, no explicit logout) simply stops refreshing its key
 * and the office falls offline on the next sweep.
 *
 * All status writes go through Office::applyStatusToGroup(), which flips a
 * single office: each Registrar counter keeps its own switch, so one
 * counter's staff never moves a sibling counter.
 */
class OfficePresence
{
    public const KEY_PREFIX = 'office-presence:staff:';

    public const SWEEP_LOCK_KEY = 'office-presence:last-sweep';

    public static function keyFor(int $userId): string
    {
        return self::KEY_PREFIX.$userId;
    }

    public static function ttlMinutes(): int
    {
        return max(5, (int) config('session.lifetime', 120));
    }

    /**
     * Mark the staff member present and bring their assigned counter online.
     *
     * Only that counter flips: sibling Registrar counters keep whatever
     * status their own staff left them in.
     */
    public static function markOnline(User $user): void
    {
        if (! $user->isStaff()) {
            return;
        }

        self::touch($user->id);

        $office = $user->office()->first();

        if (! $office) {
            return;
        }

        if (! $office->isOnline()) {
            $office->applyStatusToGroup(Office::STATUS_ONLINE);
        }
    }

    /**
     * Drop the staff member's presence and take their assigned counter
     * offline when nobody assigned to it is still present.
     */
    public static function markOffline(User $user): void
    {
        if (! $user->isStaff()) {
            return;
        }

        Cache::forget(self::keyFor((int) $user->id));

        $office = $user->office()->first();

        if (! $office) {
            return;
        }

        if ($office->isOnline() && ! self::groupHasPresence($office, (int) $user->id)) {
            $office->applyStatusToGroup(Office::STATUS_OFFLINE);
        }
    }

    /**
     * Refresh one staff member's presence key (sliding window).
     */
    public static function touch(int $userId): void
    {
        Cache::put(self::keyFor($userId), true, now()->addMinutes(self::ttlMinutes()));
    }

    public static function isPresent(int $userId): bool
    {
        return (bool) Cache::get(self::keyFor($userId), false);
    }

    /**
     * Whether any active staff assigned to the office still has a live
     * presence key or a live API token.
     */
    public static function groupHasPresence(Office $office, ?int $exceptUserId = null): bool
    {
        $staffIds = User::query()
            ->where('role', User::ROLE_STAFF)
            ->where('is_active', true)
            ->whereIn('office_id', $office->statusGroupIds())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($staffIds as $id) {
            if ($exceptUserId !== null && $id === $exceptUserId) {
                continue;
            }

            if (self::isPresent($id)) {
                return true;
            }
        }

        return self::groupHasLiveToken($staffIds, $exceptUserId);
    }

    /**
     * A staff member holding an unrevoked API token counts as present: API
     * sign-in mints a token and API sign-out revokes it, so a live token
     * means a session that was never closed.
     */
    protected static function groupHasLiveToken(array $staffIds, ?int $exceptUserId = null): bool
    {
        if ($staffIds === [] || ! Schema::hasTable('personal_access_tokens')) {
            return false;
        }

        $ids = array_values(array_filter(
            $staffIds,
            fn (int $id) => $exceptUserId === null || $id !== $exceptUserId
        ));

        if ($ids === []) {
            return false;
        }

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $ids)
            ->exists();
    }

    /**
     * Take every online office with no present staff offline.
     *
     * Covers expired sessions: their presence keys lapse without any
     * request, so the next sweep (scheduled) flips those offices offline.
     * Returns the number of offices changed.
     */
    public static function sweepStaleOffline(): int
    {
        $changed = 0;

        $groups = Office::query()
            ->whereIn('code', Office::OPERATIONAL_CODES)
            ->where('status', Office::STATUS_ONLINE)
            ->get(['id', 'code'])
            ->groupBy(fn (Office $office) => $office->groupKey());

        foreach ($groups as $offices) {
            /** @var Office $representative */
            $representative = $offices->first();

            if (! self::groupHasPresence($representative)) {
                $representative->applyStatusToGroup(Office::STATUS_OFFLINE);
                $changed++;
            }
        }

        return $changed;
    }
}
