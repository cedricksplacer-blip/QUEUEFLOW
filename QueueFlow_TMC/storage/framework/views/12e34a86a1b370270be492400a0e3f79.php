<?php $__env->startSection('title', 'Request Announcement'); ?>

<?php $__env->startSection('content'); ?>

<div class="staff-hero p-6 mb-5">
    <p class="text-[10px] uppercase tracking-widest font-semibold" style="color:#b7e0cf">QueueFlow TMC - Staff Announcements</p>
    <h2 class="text-xl font-bold mt-1">Request an Announcement</h2>
    <p class="text-xs mt-1" style="color:#d6efe4">Your request goes to the administrator for verification. It only goes live once accepted.</p>
</div>

<div class="card p-6">
    <form method="POST" action="<?php echo e(route('staff.announcements.store')); ?>" class="space-y-5">
        <?php echo csrf_field(); ?>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Title</label>
            <input class="input" name="title" value="<?php echo e(old('title')); ?>" required>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Message</label>
            <textarea class="input" name="body" rows="7" required><?php echo e(old('body')); ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Audience</label>
            <select class="input" name="audience">
                <option value="user" <?php if(old('audience') === 'user'): echo 'selected'; endif; ?>>User Only</option>
                <option value="staff" <?php if(old('audience') === 'staff'): echo 'selected'; endif; ?>>Staff Only</option>
                <option value="both" <?php if(old('audience', 'both') === 'both'): echo 'selected'; endif; ?>>User and Staff</option>
            </select>
        </div>

        <div class="flex items-center gap-2 text-xs text-slate-500">
            <span class="badge badge-waiting">Pending review</span>
            Submitted from the <strong class="text-slate-700"><?php echo e($office->name); ?></strong> office — will be verified by the admin.
        </div>

        <div class="flex gap-2">
            <button class="btn-primary">Submit for Verification</button>
            <a class="btn-secondary" href="<?php echo e(route('staff.announcements.index')); ?>">Cancel</a>
        </div>
    </form>
</div>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/announcements/create.blade.php ENDPATH**/ ?>