<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Office;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * The three statuses an administrator triages, keyed by their stored value.
     */
    private const STATUS_TILES = [
        Announcement::STATUS_PUBLISHED => 'Published',
        Announcement::STATUS_PENDING => 'Unpublished',
        Announcement::STATUS_REJECTED => 'Rejected',
    ];

    public function index(Request $request)
    {
        $query = Announcement::with(['author', 'office', 'submittedBy'])->latest();

        $status = $this->resolveStatus($request->input('status'));
        $office = $this->resolveOffice($request->input('office'));
        $audience = $this->resolveAudience($request->input('audience'));

        if ($status !== null) {
            if (array_key_exists($status, self::STATUS_TILES)) {
                $query->where('status', $status);
            } else {
                $query->where('is_active', $status === 'active');
            }
        }

        $this->scopeToOffice($query, $office);
        $this->scopeToAudience($query, $audience);

        $announcements = $query->paginate(10)->withQueryString();

        // Counts honour the chosen office but ignore the chosen status, so the
        // tiles always answer "what is sitting in this office?" and double as
        // the status navigation.
        $counts = ['all' => 0];
        $scoped = Announcement::query();
        $this->scopeToOffice($scoped, $office);
        $counts['all'] = (clone $scoped)->count();

        foreach (array_keys(self::STATUS_TILES) as $key) {
            $counts[$key] = (clone $scoped)->where('status', $key)->count();
        }

        return view('announcements.index', [
            'announcements' => $announcements,
            'counts' => $counts,
            'statusTiles' => self::STATUS_TILES,
            'status' => $status,
            'office' => $office,
            'offices' => $this->officeOptions(),
            'audience' => $audience,
            'audiences' => Announcement::AUDIENCES,
        ]);
    }

    /**
     * Normalise the status input, accepting "unpublished" as a readable alias
     * for the stored "pending" value.
     */
    private function resolveStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        if ($status === 'unpublished') {
            return Announcement::STATUS_PENDING;
        }

        return $status;
    }

    /**
     * Normalise the office filter to a real office id, or null for every office.
     *
     * Anything that is not a numeric id - including the old "global" value still
     * sitting in bookmarked links - resolves to null so the list falls back to
     * every office rather than silently matching nothing.
     */
    private function resolveOffice($office): ?string
    {
        if ($office === null || $office === '' || ! is_scalar($office)) {
            return null;
        }

        $office = (string) $office;

        return ctype_digit($office) ? $office : null;
    }

    /**
     * Restrict a query to one office. Blank means every office.
     */
    private function scopeToOffice($query, ?string $office): void
    {
        if ($office === null || $office === '') {
            return;
        }

        $query->where('office_id', (int) $office);
    }

    /**
     * Normalise the audience filter to a real audience value, or null for
     * every audience.
     */
    private function resolveAudience($audience): ?string
    {
        if ($audience === null || $audience === '' || ! is_scalar($audience)) {
            return null;
        }

        $audience = strtolower(trim((string) $audience));

        return array_key_exists($audience, Announcement::AUDIENCES) ? $audience : null;
    }

    /**
     * Restrict a query to one audience. Blank means every audience.
     */
    private function scopeToAudience($query, ?string $audience): void
    {
        if ($audience === null || $audience === '') {
            return;
        }

        $query->where('audience', $audience);
    }

    /**
     * Operational offices, plus any other office that still has announcements
     * attached so the filter can never hide existing records.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function officeOptions()
    {
        $operational = Office::whereIn('code', Office::OPERATIONAL_CODES)
            ->orderBy('name')
            ->pluck('name', 'id');

        $inUse = Announcement::query()
            ->whereNotNull('office_id')
            ->distinct()
            ->pluck('office_id');

        $legacy = Office::whereIn('id', $inUse)
            ->whereNotIn('id', $operational->keys())
            ->orderBy('name')
            ->pluck('name', 'id');

        // Keys must stay as office ids: the view uses them as filter values.
        return $operational->union($legacy)->sortBy(fn ($name) => $name);
    }

    public function create()
    {
        return view('announcements.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['nullable', 'string', 'in:user,staff,both'],
        ]);

        Announcement::create([
            ...$validated,
            'audience' => $validated['audience'] ?? Announcement::AUDIENCE_BOTH,
            'status' => Announcement::STATUS_PUBLISHED,
            'is_active' => $request->boolean('is_active', true),
            'posted_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement created.');
    }

    public function edit(Announcement $announcement)
    {
        return view('announcements.edit', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['nullable', 'string', 'in:user,staff,both'],
        ]);

        $announcement->update([
            ...$validated,
            'audience' => $validated['audience'] ?? $announcement->audience ?? Announcement::AUDIENCE_BOTH,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return back()->with('status', 'Announcement deleted.');
    }

    /**
     * Accept a staff-submitted announcement: it becomes published and active.
     */
    public function approve(Request $request, Announcement $announcement)
    {
        abort_unless($announcement->status === Announcement::STATUS_PENDING, 422, 'This announcement is not awaiting review.');

        $announcement->update([
            'status' => Announcement::STATUS_PUBLISHED,
            'is_active' => true,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', "Announcement “{$announcement->title}” accepted and is now live.");
    }

    /**
     * Reject a staff-submitted announcement; it stays hidden.
     */
    public function reject(Request $request, Announcement $announcement)
    {
        abort_unless($announcement->status === Announcement::STATUS_PENDING, 422, 'This announcement is not awaiting review.');

        $announcement->update([
            'status' => Announcement::STATUS_REJECTED,
            'is_active' => false,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', "Announcement “{$announcement->title}” rejected.");
    }
}
