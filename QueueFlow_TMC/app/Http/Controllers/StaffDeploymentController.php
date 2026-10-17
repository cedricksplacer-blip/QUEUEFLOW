<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Models\Office;
use App\Services\QueueDeploymentManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffDeploymentController extends Controller
{
    use StaffOfficeScope;

    /**
     * Batch lifecycle controls (deploy / close / complete / start) are
     * available to staff of every operational office. Every action stays
     * wired to the same QueueDeploymentManager (and database) the
     * administrator uses, scoped to the office in the staff session.
     */
    public function deploy(Request $request)
    {
        $office = $this->officeOf($request);

        $slots = $this->validatedSlots($request);

        try {
            $deployment = (new QueueDeploymentManager())->deploy($office, $slots);

            return back()->with('status', $deployment->isOpen()
                ? "{$office->name} batch #{$deployment->batch_no} is now open with {$deployment->max_slots} slots for students."
                : "{$office->name} batch has been deployed for processing.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /**
     * Close the active batch: intake stops, issued tickets stay in line.
     */
    public function closeBatch(Request $request)
    {
        $office = $this->officeOf($request);

        try {
            $deployment = (new QueueDeploymentManager())->closeBatch($office);

            return back()->with('status', "{$office->name} batch #{$deployment->batch_no} is now closed. No new tickets will be issued until a new batch is deployed.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function closeDeployment(Request $request)
    {
        $office = $this->officeOf($request);

        try {
            (new QueueDeploymentManager())->close($office);

            return back()->with('status', "{$office->name} batch has been closed and sent to history. A new batch (Batch #1) is now open.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function completeDeployment(Request $request)
    {
        $office = $this->officeOf($request);

        try {
            (new QueueDeploymentManager())->completeAndRestart($office);

            return back()->with('status', "{$office->name} batch completed. A new 100-slot batch is now open.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function startDeployment(Request $request)
    {
        $office = $this->officeOf($request);

        $slots = $this->validatedSlots($request);

        try {
            $deployment = (new QueueDeploymentManager())->start($office, $slots);

            return back()->with('status', "{$office->name} batch #{$deployment->batch_no} is now open with {$deployment->max_slots} slots for students.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /**
     * Reset the assigned office batch number to Batch 1 for closing/offline.
     *
     * Scoped to the staff member's own office via officeOf(): a 403 unless
     * the account is staff assigned to an active operational office. Past
     * batches go to history with their tickets intact and the next
     * deployment starts back at Batch 1. Never changes offices.status, so
     * an offline office stays offline. Repeating the reset is harmless:
     * with nothing left to complete it only re-anchors the sequence.
     */
    public function resetBatchNumber(Request $request)
    {
        $office = $this->officeOf($request);

        try {
            (new QueueDeploymentManager())->resetBatchNumber($office);

            return back()->with('status', 'Batch number successfully reset to Batch 1.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    /**
     * Slots requested for a freshly opened batch. Null when the form sent
     * nothing, so the office default applies.
     */
    private function validatedSlots(Request $request): ?int
    {
        if (! $request->filled('max_slots')) {
            return null;
        }

        return (int) $request->validate([
            'max_slots' => ['integer', 'min:1', 'max:500'],
        ])['max_slots'];
    }
}