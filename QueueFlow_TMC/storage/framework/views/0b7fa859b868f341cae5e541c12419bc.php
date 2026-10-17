<?php $__env->startSection('title', 'Queue History'); ?>

<?php $__env->startSection('content'); ?>

<style>
/* Dark mode: Date filter — dark text on light field */
html.dark .qf-history-date,
html.dark .qf-history-date:hover {
    background: transparent !important;
    border: 0 !important;
    padding: 0 !important;
}
html.dark .staff-page .qf-history-date label.text-slate-400 { color: #334155 !important; }
html.dark .qf-history-date .input,
html.dark .qf-history-date input[type="date"],
html.dark .qf-history-date input[type="number"],
html.dark .qf-history-date select {
    background: #0f172a !important;
    background-image: none !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
    box-shadow: none !important;
    color-scheme: dark !important;
}
html.dark .qf-history-date input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, .25) !important;
}
</style>

<div class="p-6 mb-5 bg-transparent">
    <p class="text-[10px] uppercase tracking-widest font-semibold text-emerald-600">QueueFlow TMC · Staff Queue History</p>
    <h2 class="text-xl font-bold mt-1 text-slate-900">Queue History — <?php echo e($office->name); ?></h2>
    <p class="text-xs mt-1 text-slate-500">Every current and past ticket for your office — waiting, serving, served, cancelled and no-show — newest first. Leave the date empty to review all days.</p>
</div>

<div class="card p-5 mb-5">
    <form method="GET" action="<?php echo e(route('staff.history.index')); ?>" data-qf-auto-filter class="flex flex-wrap items-end gap-3">
        <div class="qf-history-date" style="min-width: 230px; margin-left: 16px;">
            <label class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 block mb-1" for="date">Date (empty = all dates)</label>
            <input class="input" id="date" type="date" name="date" value="<?php echo e($date); ?>" max="<?php echo e(today()->toDateString()); ?>" title="Leave empty to show all dates" style="width: 230px;">
        </div>
        <div class="qf-history-date" style="min-width: 200px; margin-left: 16px;">
            <label class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 block mb-1" for="search">Search</label>
            <input class="input" id="search" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Queue #, student, ID…" autocomplete="off" style="width: 200px;">
        </div>
        <div class="qf-history-date" style="min-width: 160px; margin-left: 16px;">
            <label class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 block mb-1" for="batch">Batch #</label>
            <select class="input" id="batch" name="batch" style="width: 160px;">
                <option value="">All batches</option>
                <?php $__currentLoopData = $batches; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($b); ?>" <?php if((int) $batch === (int) $b): echo 'selected'; endif; ?>>Batch #<?php echo e($b); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
        <div class="qf-history-date" style="min-width: 160px; margin-left: 16px;">
            <label class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 block mb-1" for="status">Status</label>
            <select class="input" id="status" name="status" style="width: 160px;">
                <option value="">All statuses</option>
                <?php $__currentLoopData = ['waiting' => 'Waiting', 'serving' => 'Serving', 'served' => 'Served', 'cancelled' => 'Cancelled', 'no_show' => 'No-Show']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $val => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($val); ?>" <?php if(($status ?? '') === $val): echo 'selected'; endif; ?>><?php echo e($label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
        <div class="flex items-center gap-2 pb-[1px]">
            <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating...</span></span>
            <a href="<?php echo e(route('staff.history.index')); ?>" class="btn-secondary">Reset</a>
        </div>
    </form>
</div>

<?php echo $__env->make('partials.update-banner', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-slate-700"><?php echo e($stats['total']); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">Total</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-amber-600"><?php echo e($stats['waiting'] ?? 0); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">Waiting · Live</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-blue-600"><?php echo e($stats['serving'] ?? 0); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">Serving · Live</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-emerald-600"><?php echo e($stats['served']); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">Served</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-red-500"><?php echo e($stats['cancelled']); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">Cancelled</div>
    </div>
    <div class="card p-4 text-center">
        <div class="text-2xl font-extrabold text-orange-600"><?php echo e($stats['no_show'] ?? 0); ?></div>
        <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mt-1">No-Show</div>
    </div>
</div>

<div class="card p-5">
    <div class="flex items-center justify-between mb-3">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Tickets · newest first</span>
        <span class="text-xs text-slate-400"><?php echo e($tickets->total()); ?> found</span>
    </div>

    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Queue #</th>
                    <th>Student</th>
                    <th>Office</th>
                    <th>Course</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Issued</th>
                    <th>Served By</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $tickets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td class="font-bold text-blue-800"><?php echo e($ticket->queue_number); ?></td>
                        <td>
                            <?php echo e($ticket->student->name ?? 'Walk-in'); ?>

                            <?php if($ticket->student?->student_id): ?>
                                <div class="text-[10px] text-slate-400"><?php echo e($ticket->student->student_id); ?></div>
                            <?php endif; ?>
                            <?php if($ticket->purpose): ?>
                                <div class="text-[10px] text-slate-400"><?php echo e($ticket->purpose); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?php echo e($ticket->office->name ?? $office->name); ?></div>
                            <div class="text-[10px] text-slate-400"><?php echo e($ticket->office->code ?? $office->code); ?></div>
                        </td>
                        <td><?php echo e($ticket->course->code ?? ($ticket->service->name ?? '—')); ?></td>
                        <td>
                            <?php if($ticket->isPwd()): ?>
                                <span class="badge badge-pwd">PWD</span>
                            <?php else: ?>
                                <span class="badge badge-waiting">regular</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($ticket->isWaiting()): ?>
                                <span class="badge badge-waiting">waiting</span>
                                <div class="text-[10px] font-bold text-emerald-600 mt-0.5">● Live queue</div>
                            <?php elseif($ticket->isServing()): ?>
                                <span class="badge badge-serving">serving</span>
                                <?php if($ticket->isExpired()): ?>
                                    <span class="badge badge-cancelled">expired</span>
                                <?php endif; ?>
                                <div class="text-[10px] font-bold text-emerald-600 mt-0.5">● Live queue</div>
                            <?php elseif($ticket->isServed()): ?>
                                <span class="badge badge-served">served</span>
                                <div class="text-[10px] text-slate-400 mt-0.5">● History</div>
                            <?php elseif($ticket->isNoShow()): ?>
                                <span class="badge badge-cancelled">No-Show</span>
                                <?php if($ticket->no_show_at): ?><div class="text-[10px] text-slate-400 mt-0.5"><?php echo e($ticket->no_show_at->format('g:i A')); ?></div><?php endif; ?>
                                <div class="text-[10px] text-slate-400 mt-0.5">● History</div>
                            <?php else: ?>
                                <span class="badge badge-cancelled">cancelled</span>
                                <div class="text-[10px] text-slate-400 mt-0.5">● History</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?php echo e($ticket->created_at->format('g:i A')); ?></div>
                            <div class="text-[10px] text-slate-400"><?php echo e($ticket->created_at->format('M j, Y')); ?></div>
                        </td>
                        <td><?php echo e($ticket->servedByStaff->name ?? '—'); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="8" class="text-center text-slate-400 py-6">No tickets match these filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-5"><div class="p-4"><?php echo e($tickets->links('vendor.pagination.queueflow')); ?></div></div>

<?php echo $__env->make('partials.auto-filter', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php echo $__env->make('partials.live-refresh', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* History rows never rewrite themselves (date filters + pagination
       stay put); new tickets for this office raise a banner instead. */
    var firstSeen = null;
    var lastSeen = 0;
    function armDismiss() {
        var dismiss = document.getElementById('qfStaleDismiss');
        if (dismiss && !dismiss.dataset.qfPingWired) {
            dismiss.dataset.qfPingWired = '1';
            dismiss.addEventListener('click', function () { firstSeen = lastSeen; });
        }
    }
    if (window.QfLive) {
        window.QfLive.poll({
            url: '<?php echo e(route('staff.queues.state')); ?>',
            interval: 20000,
            onData: function (data) {
                var id = data && data.latest_ticket_id ? data.latest_ticket_id : 0;
                var done = data ? ((data.served_today || 0) + (data.no_show_count || 0)) : 0;
                var key = id + ':' + done;
                if (firstSeen === null) { firstSeen = key; lastSeen = key; armDismiss(); return; }
                armDismiss();
                lastSeen = key;
                if (key !== firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New queue updates for your office — refresh the view to see them.');
                }
            }
        });
    }
});
</script>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/history/index.blade.php ENDPATH**/ ?>