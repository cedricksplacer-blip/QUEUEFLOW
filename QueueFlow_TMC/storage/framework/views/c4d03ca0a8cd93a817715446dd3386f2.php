<?php $__env->startSection('title', 'Cancellation Alerts'); ?>

<?php $__env->startSection('content'); ?>
<div class="qf-dashboard w-full max-w-[1080px] mx-auto px-3 sm:px-4 min-w-0">

    <div class="qf-reveal mb-5">
        <h3 class="text-sm font-bold text-slate-900">Cancellation Alerts — <?php echo e($office->name); ?></h3>
        <p class="text-xs text-slate-500 mt-1">
            <?php echo e($totalCount); ?> alert(s) · <?php echo e($unreadCount); ?> unread. New cancellations appear here automatically.
        </p>
    </div>

    <?php echo $__env->make('partials.update-banner', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">
        <div class="p-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <div class="flex gap-2">
                <a href="<?php echo e(route('staff.notifications.index', ['filter' => 'all'])); ?>" class="btn-secondary text-xs <?php echo e($filter !== 'unread' ? 'font-bold' : ''); ?>">All</a>
                <a href="<?php echo e(route('staff.notifications.index', ['filter' => 'unread'])); ?>" class="btn-secondary text-xs <?php echo e($filter === 'unread' ? 'font-bold' : ''); ?>">Unread (<?php echo e($unreadCount); ?>)</a>
            </div>
            <form method="POST" action="<?php echo e(route('staff.notifications.read-all')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn-secondary text-xs">Mark all as read</button>
            </form>
        </div>

        <div class="p-3">
            <?php $__empty_1 = true; $__currentLoopData = $notifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $notification): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="rounded-xl border px-3 py-2.5 mb-2 <?php echo e($notification->is_read ? 'border-slate-100 bg-white' : 'border-red-200 bg-red-50'); ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-bold text-slate-900"><?php echo e($notification->title); ?></span>
                                <?php if(!$notification->is_read): ?><span class="badge badge-cancelled">new</span><?php endif; ?>
                            </div>
                            <p class="text-xs text-slate-600 mt-1"><?php echo e($notification->message); ?></p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-0.5 mt-2 text-[11px] text-slate-500">
                                <div><strong>Student:</strong> <?php echo e($notification->student_name); ?> (<?php echo e($notification->student_id_no ?? '—'); ?>)</div>
                                <div><strong>Ticket:</strong> <?php echo e($notification->queue_number); ?></div>
                                <div><strong>Office:</strong> <?php echo e($notification->office_name); ?></div>
                                <div><strong>Purpose:</strong> <?php echo e($notification->purpose ?? '—'); ?></div>
                                <div><strong>Priority:</strong> <?php echo e(ucfirst($notification->priority ?? 'regular')); ?></div>
                                <div><strong>Cancelled:</strong> <?php echo e(optional($notification->cancelled_at)->format('M d, Y g:i A') ?? '—'); ?></div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5 flex-shrink-0">
                            <a href="<?php echo e(route('staff.notifications.show', $notification)); ?>" class="btn-secondary text-xs text-center">Details</a>
                            <?php if(!$notification->is_read): ?>
                                <form method="POST" action="<?php echo e(route('staff.notifications.read', $notification)); ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn-primary text-xs w-full">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-slate-500 py-6 text-center">
                    <?php if($filter === 'unread'): ?>
                        No unread cancellation alerts. You're all caught up.
                    <?php else: ?>
                        No cancellation alerts for <?php echo e($office->name); ?> yet.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

    </div>

    <div class="card mt-5"><div class="p-4"><?php echo e($notifications->links('vendor.pagination.queueflow')); ?></div></div>

</div>
<?php echo $__env->make('partials.live-refresh', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* New cancellation alerts raise a banner; read states and paging stay put. */
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
            url: '<?php echo e(route('staff.notifications.recent')); ?>',
            interval: 20000,
            onData: function (data) {
                var rows = data && Array.isArray(data.notifications) ? data.notifications : [];
                var top = 0;
                rows.forEach(function (n) {
                    var id = parseInt(n && n.id, 10) || 0;
                    if (id > top) { top = id; }
                });
                lastSeen = top;
                if (firstSeen === null) { firstSeen = top; armDismiss(); return; }
                armDismiss();
                if (top > firstSeen && window.QfStaleBanner) {
                    window.QfStaleBanner.show('New cancellation alerts — refresh the view to see them.');
                }
            }
        });
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/notifications/index.blade.php ENDPATH**/ ?>