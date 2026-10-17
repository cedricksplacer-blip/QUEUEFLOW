<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\OfficeStation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficeStationController extends Controller
{
    public function create(Office $office)
    {
        $this->ensureOperational($office);

        return view('offices.station-form', [
            'office' => $office,
            'station' => new OfficeStation([
                'opening_time' => '08:00',
                'closing_time' => '15:00',
                'min_daily_students' => 40,
                'target_daily_students' => 60,
                'max_daily_slots' => 100,
                'location' => OfficeStation::CAMPUS_LOCATION,
            ]),
        ]);
    }

    public function store(Request $request, Office $office)
    {
        $this->ensureOperational($office);

        $validated = $this->validated($request, $office);

        $office->stations()->create($validated);

        return redirect()->route('admin.offices.index')
            ->with('status', "Station added to {$office->name}.");
    }

    public function edit(Office $office, OfficeStation $station)
    {
        $this->ensureOperational($office);
        $this->ensureBelongsTo($station, $office);

        return view('offices.station-form', compact('office', 'station'));
    }

    public function update(Request $request, Office $office, OfficeStation $station)
    {
        $this->ensureOperational($office);
        $this->ensureBelongsTo($station, $office);

        $station->update($this->validated($request, $office, $station));

        return redirect()->route('admin.offices.index')
            ->with('status', "{$station->name} updated.");
    }

    public function destroy(Office $office, OfficeStation $station)
    {
        $this->ensureOperational($office);
        $this->ensureBelongsTo($station, $office);

        // Deleting nulls out the station reference on historical tickets and on
        // services, so a station with queue history should be retired instead.
        if ($station->queueTickets()->exists()) {
            return back()->withErrors([
                'station' => 'This station has queue history. Deactivate it instead of deleting it so past tickets keep their station.',
            ]);
        }

        $name = $station->name;
        $station->delete();

        return back()->with('status', "{$name} removed.");
    }

    private function ensureOperational(Office $office): void
    {
        abort_unless(in_array($office->code, Office::OPERATIONAL_CODES, true), 404);
    }

    private function ensureBelongsTo(OfficeStation $station, Office $office): void
    {
        abort_unless($station->office_id === $office->id, 404);
    }

    private function validated(Request $request, Office $office, ?OfficeStation $station = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('office_stations')
                    ->where('office_id', $office->id)
                    ->ignore($station?->id),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'course_codes' => ['nullable', 'array'],
            'course_codes.*' => ['string', 'in:'.implode(',', User::COURSES)],
            'min_daily_students' => ['required', 'integer', 'min:0', 'max:10000'],
            'target_daily_students' => ['required', 'integer', 'min:0', 'max:10000', 'gte:min_daily_students'],
            'max_daily_slots' => ['required', 'integer', 'min:1', 'max:10000', 'gte:target_daily_students'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['course_codes'] = $validated['course_codes'] ?? [];

        return $validated;
    }
}
