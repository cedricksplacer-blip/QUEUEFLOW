<?php $__env->startSection('title','Queue Activity History'); ?>
<?php $__env->startSection('content'); ?>
<div class="card p-4 mb-5">
    <div class="text-sm font-bold text-slate-900">Queue Activity History</div>
    <p class="text-[11px] text-slate-500 mt-1">Every queue ticket from all offices and Registrar counters, latest first. Reads the live database, so new activity appears on refresh.</p>
</div>
<div class="flex flex-col justify-between gap-3 mb-5">
    <form method="GET" data-qf-auto-filter class="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3 flex-1 items-center">
        <input class="input" name="search" value="<?php echo e(request('search')); ?>" placeholder="Search queue, student, ID, purpose" autocomplete="off">
        <select class="input" name="office_id"><option value="">All offices</option><?php $__currentLoopData = $offices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $office): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($office->id); ?>" <?php if(request('office_id') == $office->id): echo 'selected'; endif; ?>><?php echo e($office->name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select>
        <select class="input" name="course_id"><option value="">All courses</option><?php $__currentLoopData = $courses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $course): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($course->id); ?>" <?php if(request('course_id') == $course->id): echo 'selected'; endif; ?>><?php echo e($course->code); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select>
        <select class="input" name="priority"><option value="">All priorities</option><?php $__currentLoopData = ['regular','pwd']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $priority): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($priority); ?>" <?php if(request('priority')===$priority): echo 'selected'; endif; ?>><?php echo e(strtoupper($priority)); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select>
        <select class="input" name="status"><option value="">All statuses</option><?php $__currentLoopData = ['waiting','serving','served','cancelled','no_show']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($status); ?>" <?php if(request('status')===$status): echo 'selected'; endif; ?>><?php echo e($status === 'no_show' ? 'No-Show' : ucfirst($status)); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select>
        <input class="input" type="date" name="date" value="<?php echo e($date); ?>" title="Empty shows all dates">
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating…</span></span>
    </form>
    <?php if(request()->filled('search') || request()->filled('office_id') || request()->filled('course_id') || request()->filled('priority') || request()->filled('status') || request()->filled('date')): ?>
        <div><a href="<?php echo e(route('admin.activity.index')); ?>" class="btn-secondary">Reset</a></div>
    <?php endif; ?>
</div>
<?php echo $__env->make('partials.update-banner', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<div class="card"><div class="table-wrap"><table class="admin-table"><thead><tr><th>Queue</th><th>Office / Counter</th><th>Course</th><th>Student</th><th>Priority</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>
<?php $__empty_1 = true; $__currentLoopData = $activities; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $activity): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><tr>
<td class="font-bold"><?php echo e($activity->queue_number); ?></td>
<td><div class="font-medium"><?php echo e($activity->office->name ?? '—'); ?></div><div class="text-[11px] text-slate-400"><?php echo e($activity->office->code ?? ""); ?></div></td>
<td><?php echo e($activity->course->code ?? "—"); ?></td>
<td><div class="font-medium"><?php echo e($activity->student->name ?? 'Walk-in'); ?></div><div class="text-[11px] text-slate-400"><?php echo e($activity->student->student_id ?? ""); ?></div></td>
<td><?php if($activity->isPwd()): ?>
    
    <button type="button" class="js-pwd-proof badge badge-pwd qf-pwd-badge" title="<?php echo e(filled($activity->priority_evidence) ? 'View PWD verification proof' : 'No PWD proof picture available for this student.'); ?>" aria-label="View PWD verification proof for queue <?php echo e($activity->queue_number); ?>" data-url="<?php echo e(filled($activity->priority_evidence) ? route('admin.queues.evidence', $activity) : ''); ?>" data-student="<?php echo e($activity->student->name ?? 'Walk-in'); ?>" data-student-id="<?php echo e($activity->student->student_id ?? ''); ?>" data-office="<?php echo e($activity->office->name ?? ''); ?>" data-queue="<?php echo e($activity->queue_number); ?>" data-purpose="<?php echo e($activity->purpose ?? ''); ?>" data-status="<?php echo e($activity->status); ?>" data-time="<?php echo e(optional($activity->created_at)->format('M d, Y h:i A') ?? ''); ?>">PWD</button>
<?php else: ?><span class="text-slate-400">Regular</span><?php endif; ?></td>
<td><span class="badge badge-<?php echo e($activity->status === 'no_show' ? 'cancelled' : $activity->status); ?>"><?php echo e($activity->status === 'no_show' ? 'No-Show' : ucfirst($activity->status)); ?></span></td>
<td class="text-slate-500 whitespace-nowrap"><?php echo e($activity->created_at->format('M d, Y h:i A')); ?></td>
</tr><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><tr><td colspan="7" class="text-center text-slate-500 py-10">No queue activity found.</td></tr><?php endif; ?>
</tbody></table></div><div class="p-4"><?php echo e($activities->links('vendor.pagination.queueflow')); ?></div></div>
<?php echo $__env->make('partials.auto-filter', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php echo $__env->make('partials.live-refresh', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php echo $__env->make('partials.pwd-proof-modal', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Banner-only freshness for the history table: filtered views never reload. */
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
            url: '<?php echo e(route('admin.queue-ping')); ?>',
            params: { office_id: '<?php echo e(request('office_id')); ?>' },
            interval: 20000,
            onData: function (data) {
                lastSeen = data && data.latest_ticket_id ? data.latest_ticket_id : 0;
                if (firstSeen === null) { firstSeen = lastSeen; armDismiss(); return; }
                armDismiss();
                if (lastSeen > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New queue activity — refresh the view to see it.');
                }
            }
        });
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/admin/activity.blade.php ENDPATH**/ ?>