<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "one queue number per batch" a database guarantee instead of a convention.
 *
 * WHY THIS EXISTS
 *
 * QueueTicket::nextNumberForDeployment() derives the next number by reading the
 * most recently created ticket in the batch and adding one. That is correct for
 * one request at a time and wrong for two: two students tapping "GET QUEUE
 * NUMBER" in the same second both read the same last row, both compute the same
 * number, and both insert it. The number is what the counter calls and what the
 * student is told to wait for, so a duplicate is not cosmetic - the second
 * student is handed a number that will never be called.
 *
 * PHP has no cross-process mutex here, so the read-then-insert cannot be made
 * atomic in application code alone. The constraint below is what actually
 * closes it: MySQL refuses the second insert, the transaction rolls back, and
 * the caller is told the office is momentarily busy instead of being issued a
 * duplicate number.
 *
 * WHY (deployment_id, queue_number) AND NOT (office_id, queue_number)
 *
 * Numbers restart at 001 for every batch by design - see
 * QueueDeployment::restartResetFor() and the note in nextNumberForDeployment().
 * CASH-001 on Monday's batch and CASH-001 on Tuesday's batch are two different
 * tickets in two different queues, so the uniqueness has to be scoped to the
 * batch, not to the office. The earlier probe on this database confirmed the
 * distinction: three CASH-001 rows exist for one office on one day, each in its
 * own deployment, and zero duplicates within any single deployment.
 *
 * NULLABLE deployment_id
 *
 * A ticket always carries a deployment_id in practice, but the column is
 * nullable, and MySQL permits unlimited rows with a NULL in a unique index - so
 * legacy or hand-inserted rows without a batch cannot block the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queue_tickets')) {
            return;
        }

        $this->assertNoDuplicatesWithinBatches();

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unique(
                ['deployment_id', 'queue_number'],
                'queue_tickets_deployment_queue_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique('queue_tickets_deployment_queue_number_unique');
        });
    }

    /**
     * Refuse to migrate if the constraint cannot be satisfied.
     *
     * The alternative - dropping the offending rows - would silently destroy
     * queue history, which is the one thing this table must never lose. If this
     * ever trips, the duplicates are real and an administrator has to decide
     * which number each ticket should carry.
     */
    private function assertNoDuplicatesWithinBatches(): void
    {
        $duplicates = DB::table('queue_tickets')
            ->select('deployment_id', 'queue_number', DB::raw('COUNT(*) as ticket_count'))
            ->whereNotNull('deployment_id')
            ->groupBy('deployment_id', 'queue_number')
            ->having('ticket_count', '>', 1)
            ->limit(5)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($row) => "deployment {$row->deployment_id} / {$row->queue_number} ({$row->ticket_count} tickets)")
            ->implode('; ');

        throw new RuntimeException(
            'Cannot add the per-batch queue number constraint: these numbers are already '
            ."duplicated inside a single batch - {$detail}. No rows were changed. "
            .'Resolve the duplicates (rename one ticket per batch, or move it to the batch it '
            .'belongs to) and run the migration again.'
        );
    }
};
