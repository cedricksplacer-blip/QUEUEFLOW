<?php $__env->startSection('title', 'My Office'); ?>

<?php $__env->startSection('content'); ?>

<div class="staff-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC · Staff Office Profile</p>
    <h2 class="text-xl font-bold mt-1" style="color:#fff !important"><?php echo e($office->name); ?></h2>
    <p class="text-xs mt-1" style="color:#d6efe4">
        Code <?php echo e($office->code); ?>

        <?php if($office->description): ?> · <?php echo e($office->description); ?><?php endif; ?>
    </p>
    <p class="text-xs mt-2" style="color:#d6efe4">
        <?php if($office->activeDeployment): ?>
            Batch #<?php echo e($office->activeDeployment->batch_no); ?> ·
            <?php echo e(strtoupper($office->activeDeployment->status)); ?> ·
            <?php echo e($office->activeDeployment->submittedCount()); ?> submitted ·
            <?php echo e($office->activeDeployment->servedCount()); ?> served
        <?php else: ?>
            No active batch right now.
        <?php endif; ?>
    </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Office Setup</span>
        <div class="mt-3 space-y-3 text-xs">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Status</span>
                <?php if($office->is_active): ?>
                    <span class="badge badge-served">active</span>
                <?php else: ?>
                    <span class="badge badge-cancelled">inactive</span>
                <?php endif; ?>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-slate-500">Online Status</span>
                <div class="flex items-center gap-2">
                    <span class="badge <?php echo e($office->isOnline() ? 'badge-online' : 'badge-offline'); ?>">
                        <?php echo e(strtoupper($office->status ?: \App\Models\Office::STATUS_OFFLINE)); ?>

                    </span>
                </div>
            </div>
            <p class="text-[10px] text-slate-400">Managed automatically — online while assigned staff are signed in.</p>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Acceptance Window</span>
                <span class="font-semibold text-slate-700">
                    <?php echo e($office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : 'N/A'); ?> –
                    <?php echo e($office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : 'N/A'); ?>

                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Slot Limit per Batch</span>
                <span class="font-semibold text-slate-700"><?php echo e($office->slotLimit()); ?></span>
            </div>
        </div>
    </div>

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Staff Members</span>
        <div class="mt-3 space-y-2">
            <?php $__empty_1 = true; $__currentLoopData = $office->staff; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $member): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="flex items-center gap-2 text-xs">
                    <span class="badge badge-served"><?php echo e(strtoupper(substr($member->name, 0, 1))); ?></span>
                    <div>
                        <div class="font-semibold text-slate-700"><?php echo e($member->name); ?></div>
                        <div class="text-[10px] text-slate-400"><?php echo e($member->email); ?></div>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-slate-500">No active staff assigned.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card p-5">
        <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Assignments</span>
        <div class="mt-3">
            <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mb-1">Courses</div>
            <?php $__empty_1 = true; $__currentLoopData = $office->courses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $course): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100 last:border-0">
                    <span class="font-bold text-blue-800"><?php echo e($course->code); ?></span>
                    <span class="text-slate-500"><?php echo e($course->assignedStaff->name ?? 'Unassigned'); ?></span>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-slate-500">No courses assigned to this office.</p>
            <?php endif; ?>

            <div class="text-[10px] uppercase tracking-widest font-semibold text-slate-400 mb-1 mt-4">Services</div>
            <?php $__empty_1 = true; $__currentLoopData = $office->services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100 last:border-0">
                    <span class="font-semibold text-slate-700"><?php echo e($service->name); ?></span>
                    <?php if($service->is_active): ?>
                        <span class="badge badge-served">active</span>
                    <?php else: ?>
                        <span class="badge badge-cancelled">inactive</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-slate-500">No services configured.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-5">
    <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Stations</span>
    <?php if($office->stations->count()): ?>
        <div class="flex flex-wrap gap-2 mt-3">
            <?php $__currentLoopData = $office->stations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $station): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="badge <?php echo e($station->is_active ? 'badge-serving' : 'badge-cancelled'); ?>"><?php echo e($station->name); ?></span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php else: ?>
        <p class="text-sm text-slate-500 mt-3">No stations configured for this office.</p>
    <?php endif; ?>
</div>

<div class="card p-5 mt-5">
    <span class="text-[10px] uppercase tracking-widest font-semibold text-slate-400">Batch Management</span>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500">
            <?php if($office->activeDeployment): ?>
                Currently on <strong class="text-slate-700">Batch #<?php echo e($office->activeDeployment->batch_no); ?></strong>
                (<?php echo e(strtoupper($office->activeDeployment->status)); ?>).
            <?php else: ?>
                No active batch right now.
            <?php endif; ?>
            <span class="block text-[10px] text-slate-400 mt-1">Reset to Batch 1 when closing or setting this office offline. History and tickets are kept.</span>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php echo $__env->make('staff.partials.reset-batch-number', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
        </div>
    </div>
</div>

<p class="text-[11px] text-slate-400 mt-4">Office setup, staff, courses, services and stations are managed by the administrator and reflected here automatically.</p>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/office.blade.php ENDPATH**/ ?>