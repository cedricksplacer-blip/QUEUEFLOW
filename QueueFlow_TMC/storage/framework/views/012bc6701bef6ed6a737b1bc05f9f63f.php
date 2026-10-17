<?php $__env->startSection('title', 'Cancellation Alerts'); ?>

<?php $__env->startSection('content'); ?>
<div class="card p-4 mb-4">
    <h3 class="text-sm font-bold">Cancellation Alerts — all offices</h3>
    <p class="text-xs text-slate-500 mt-1">Every student cancellation across the campus, newest first.</p>
</div>

<div class="card p-4 mb-4">
    <form method="GET" action="<?php echo e(route('admin.notifications.index')); ?>" data-qf-auto-filter class="flex flex-wrap gap-2 items-end">
        <div>
            <label class="text-xs font-semibold">Office</label>
            <select name="office_id" class="input text-xs">
                <option value="">All offices</option>
                <?php $__currentLoopData = $offices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $office): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($office->id); ?>" <?php echo e((int) $officeId === $office->id ? 'selected' : ''); ?>><?php echo e($office->name); ?> (<?php echo e($office->code); ?>)</option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating...</span></span>
    </form>
</div>

<?php echo $__env->make('partials.update-banner', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<div class="card p-4">
    <?php $__empty_1 = true; $__currentLoopData = $notifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $notification): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
        <div class="rounded-xl border px-3 py-2.5 mb-2 <?php echo e($notification->is_read ? 'border-slate-100 bg-white' : 'border-red-200 bg-red-50'); ?>">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-xs font-bold"><?php echo e($notification->title); ?></span>
                        <span class="badge badge-cancelled"><?php echo e($notification->office_code); ?></span>
                        <?php if(!$notification->is_read): ?><span class="badge badge-cancelled">new</span><?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-600 mt-1"><?php echo e($notification->message); ?></p>
                    <div class="text-[11px] text-slate-500 mt-1">
                        <?php echo e($notification->student_name); ?> (<?php echo e($notification->student_id_no ?? '—'); ?>) · <?php echo e($notification->queue_number); ?> · <?php echo e($notification->purpose ?? '—'); ?> · <?php echo e(optional($notification->cancelled_at)->format('M d, Y g:i A')); ?>

                    </div>
                </div>
                <a href="<?php echo e(route('admin.notifications.show', $notification)); ?>" class="btn-secondary text-xs flex-shrink-0">Details</a>
            </div>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
        <p class="text-sm text-slate-500 py-6 text-center">No cancellation alerts yet.</p>
    <?php endif; ?>

    <?php if($notifications->hasPages()): ?>
        <div class="mt-3"><?php echo e($notifications->links('vendor.pagination.queueflow')); ?></div>
    <?php endif; ?>
</div>
<?php echo $__env->make('partials.auto-filter', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php echo $__env->make('partials.live-refresh', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* New cancellation alerts surface as a banner; the list never reloads itself. */
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
            interval: 20000,
            onData: function (data) {
                lastSeen = data && data.latest_notification_id ? data.latest_notification_id : 0;
                if (firstSeen === null) { firstSeen = lastSeen; armDismiss(); return; }
                armDismiss();
                if (lastSeen > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New cancellation alerts — refresh the view to see them.');
                }
            }
        });
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/admin/notifications/index.blade.php ENDPATH**/ ?>