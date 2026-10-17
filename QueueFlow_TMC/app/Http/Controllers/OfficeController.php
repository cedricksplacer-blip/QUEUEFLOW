<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\QueueDeploymentManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OfficeController extends Controller
{
    public function index()
    {
        // Official offices plus any active custom office the admin added, so
        // a newly created office appears here right after it is saved.
        // Retired (inactive) custom rows stay hidden.
        $offices = Office::where(fn ($q) => $q
                ->whereIn('code', Office::OPERATIONAL_CODES)
                ->orWhere('is_active', true))
            ->withCount(['staff', 'queueTickets'])
            ->with([
                'activeDeployment',
                'stations',
                'services.station',
                'staff' => fn ($q) => $q->where('is_active', true)->orderBy('name'),
            ])
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('offices.index', compact('offices'));
    }

    public function create()
    {
        return view('offices.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = true;

        $office = Office::create($validated);

        // A new office needs at least one service before any ticket can be
        // issued against it, so seed a general one. Stations stay optional
        // and can be added from the office card afterwards.
        $office->services()->firstOrCreate(
            ['name' => 'General Services'],
            ['description' => null, 'requires_course' => false, 'is_active' => true]
        );

        return redirect()->route('admin.offices.index')->with('status', 'Office added.');
    }

    public function edit(Office $office)
    {
        $office->load('activeDeployment');

        return view('offices.edit', compact('office'));
    }

    public function update(Request $request, Office $office)
    {
        $validated = $this->validated($request, $office);

        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = $request->boolean('is_active');

        $office->update($validated);

        return redirect()->route('admin.offices.index')->with('status', 'Office updated.');
    }

    public function toggleActive(Office $office)
    {
        $office->update(['is_active' => ! $office->is_active]);

        return back()->with('status', $office->is_active
            ? "{$office->name} is now active."
            : "{$office->name} is now inactive.");
    }

    /**
     * Flip the online/offline switch for one office.
     *
     * Each office — including every per-course Registrar counter — carries
     * its own switch, so toggling one counter leaves its siblings untouched.
     * A JSON request - the forms fetch the admin screens with - gets the new
     * status back; a classic form post just bounces back with a message.
     */
    public function toggleStatus(Request $request, Office $office)
    {
        $requested = $request->input('status');

        $status = in_array($requested, [Office::STATUS_ONLINE, Office::STATUS_OFFLINE], true)
            ? $requested
            : ($office->isOnline() ? Office::STATUS_OFFLINE : Office::STATUS_ONLINE);

        $updated = $office->applyStatusToGroup($status);
        $message = $updated > 0
            ? Office::statusMessage($office, $status)
            : 'The office status is unchanged.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'status' => $status,
                'offices' => $updated,
            ]);
        }

        return back()->with('status', $message);
    }

    public function destroy(Office $office)
    {
        if ($office->staff()->exists() || $office->queueTickets()->exists()) {
            return back()->withErrors(['office' => 'This office has staff or queue history. Deactivate it instead of deleting it.']);
        }

        $office->delete();

        return back()->with('status', 'Office removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Deployment actions
    |--------------------------------------------------------------------------
    */

    public function deploy(Office $office)
    {
        try {
            $deployment = (new QueueDeploymentManager())->deploy($office);

            return back()->with('status', $deployment->isOpen()
                ? "{$office->name} batch #{$deployment->batch_no} is now open with {$deployment->max_slots} slots for students."
                : "{$office->name} batch has been deployed for processing.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function closeDeployment(Office $office)
    {
        try {
            (new QueueDeploymentManager())->close($office);

            return back()->with('status', "{$office->name} batch has been closed and sent to history. A new batch (Batch #1) is now open.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function completeDeployment(Office $office)
    {
        try {
            (new QueueDeploymentManager())->completeAndRestart($office);

            return back()->with('status', "{$office->name} batch completed. A new 100-slot batch is now open.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function startDeployment(Office $office)
    {
        try {
            (new QueueDeploymentManager())->start($office);

            return back()->with('status', "A new batch is now open for {$office->name}.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    private function validated(Request $request, ?Office $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:offices,code'.($ignore ? ",{$ignore->id}" : '')],
            'description' => ['nullable', 'string'],
            'office_type' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:255'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'],
            'max_slots' => ['required', 'integer', 'min:1', 'max:500'],
        ]);
    }
}