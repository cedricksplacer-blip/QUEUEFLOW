

<?php $__env->startSection('title', 'Announcements'); ?>

<?php $__env->startSection('content'); ?>

<div class="staff-hero p-6 mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC - Staff</p>
            <p class="text-xs mt-1" style="color:#d6efe4">Official notices for all offices. You can also submit your own - it needs admin approval before it goes live.</p>
        </div>
        <a href="<?php echo e(route('staff.announcements.create')); ?>" class="btn-secondary text-xs" style="background:#fff;color:#123B2E">+ Request Announcement</a>
    </div>
</div>

<?php $__empty_1 = true; $__currentLoopData = $announcements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $notice): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <div class="card p-5 mb-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-800"><a href="<?php echo e(route('staff.announcements.show', $notice)); ?>" class="hover:underline"><?php echo e($notice->title); ?></a></h3>
                <div class="text-[11px] text-slate-400 mt-1">
                    Posted by <?php echo e($notice->author->name ?? 'Administrator'); ?>

                    <?php if($notice->office): ?> - <?php echo e($notice->office->name); ?><?php endif; ?>
                    - <?php echo e($notice->created_at->format('M j, Y - g:i A')); ?>

                    · <?php echo e($notice->audienceLabel()); ?>

                </div>
            </div>
            <span class="flex-shrink-0 inline-flex items-center gap-1">
                <span class="badge badge-served">active</span>
                <?php if(in_array((int) $notice->id, $readIds ?? [], true)): ?>
                    <span class="badge bg-slate-100 text-slate-600">Read</span>
                <?php else: ?>
                    <span class="badge badge-waiting">Unread</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="text-sm text-slate-600 mt-3 leading-relaxed whitespace-pre-line"><?php echo e($notice->body); ?></div>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <div class="card p-8 text-center">
        <div class="text-3xl mb-2">📢</div>
        <p class="text-sm font-semibold text-slate-700">No active announcements.</p>
        <p class="text-xs text-slate-500 mt-1">Published announcements will appear here.</p>
    </div>
<?php endif; ?>

<?php if($myAnnouncements->isNotEmpty()): ?>
    <div class="mt-8 mb-3">
        <p class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">My Announcement Requests</p>
    </div>

    <?php $__currentLoopData = $myAnnouncements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $request): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php
            $requestBadge = [
                'pending' => ['badge-waiting', 'Pending admin review'],
                'published' => ['badge-served', 'Accepted - live'],
                'rejected' => ['badge-cancelled', 'Rejected'],
            ][$request->status] ?? ['bg-slate-100 text-slate-600', ucfirst($request->status)];
        ?>
        <div class="card p-5 mb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-bold text-slate-800"><?php echo e($request->title); ?></h3>
                    <div class="text-[11px] text-slate-400 mt-1">
                        Submitted <?php echo e($request->created_at->format('M j, Y - g:i A')); ?>

                        <?php if($request->status === 'published' && $request->reviewedBy): ?>
                            - Accepted by <?php echo e($request->reviewedBy->name); ?>

                        <?php elseif($request->status === 'rejected' && $request->reviewedBy): ?>
                            - Reviewed by <?php echo e($request->reviewedBy->name); ?>

                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge <?php echo e($requestBadge[0]); ?> flex-shrink-0"><?php echo e($requestBadge[1]); ?></span>
            </div>
            <div class="text-sm text-slate-600 mt-3 leading-relaxed whitespace-pre-line"><?php echo e($request->body); ?></div>
            <?php if($request->status === 'rejected'): ?>
                <div class="mt-3">
                    <a href="<?php echo e(route('staff.announcements.create')); ?>" class="btn-secondary text-xs">Submit New Request</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/announcements/index.blade.php ENDPATH**/ ?>