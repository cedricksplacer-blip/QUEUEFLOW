
<?php $__env->startSection('title','Announcements'); ?>
<?php $__env->startSection('content'); ?>
<?php
    $statusBadges = [
        \App\Models\Announcement::STATUS_PENDING => ['badge-waiting', $statusTiles[\App\Models\Announcement::STATUS_PENDING]],
        \App\Models\Announcement::STATUS_PUBLISHED => ['badge-served', $statusTiles[\App\Models\Announcement::STATUS_PUBLISHED]],
        \App\Models\Announcement::STATUS_REJECTED => ['badge-cancelled', $statusTiles[\App\Models\Announcement::STATUS_REJECTED]],
    ];
    $tileStyles = [
        \App\Models\Announcement::STATUS_PUBLISHED => ['badge-served', 'border-emerald-200', 'text-emerald-700'],
        \App\Models\Announcement::STATUS_PENDING => ['badge-waiting', 'border-amber-200', 'text-amber-700'],
        \App\Models\Announcement::STATUS_REJECTED => ['badge-cancelled', 'border-rose-200', 'text-rose-700'],
    ];
    $officeValue = $office ?? 'all';
?>


<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
    <a href="<?php echo e(route('admin.announcements.index', ['office' => $officeValue])); ?>"
       class="card p-4 border <?php echo e($status === null ? 'border-blue-300 ring-1 ring-blue-200' : 'border-slate-100'); ?> hover:border-blue-300 transition">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">All statuses</div>
        <div class="text-2xl font-black mt-1 text-slate-800"><?php echo e($counts['all']); ?></div>
    </a>
    <?php $__currentLoopData = $statusTiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php [$badgeClass, $borderClass, $textClass] = $tileStyles[$key]; ?>
        <a href="<?php echo e(route('admin.announcements.index', ['office' => $officeValue, 'status' => $key])); ?>"
           class="card p-4 border <?php echo e($status === $key ? 'border-blue-300 ring-1 ring-blue-200' : $borderClass); ?> hover:border-blue-300 transition">
            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-bold"><?php echo e($label); ?></div>
            <div class="text-2xl font-black mt-1 <?php echo e($textClass); ?>"><?php echo e($counts[$key]); ?></div>
        </a>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>


<div class="flex flex-col sm:flex-row gap-3 mb-5">
    <form method="GET" data-qf-auto-filter class="card p-3 flex flex-wrap gap-2 items-center">
        <select class="input" name="office">
            <option value="all" <?php if($officeValue === 'all'): echo 'selected'; endif; ?>>All offices</option>
            <?php $__currentLoopData = $offices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $officeId => $officeName): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($officeId); ?>" <?php if($officeValue === (string) $officeId): echo 'selected'; endif; ?>><?php echo e($officeName); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            <?php $__currentLoopData = $statusTiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($key); ?>" <?php if($status === $key): echo 'selected'; endif; ?>><?php echo e($label); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            <option value="active" <?php if($status === 'active'): echo 'selected'; endif; ?>>Visible to students</option>
            <option value="inactive" <?php if($status === 'inactive'): echo 'selected'; endif; ?>>Hidden from students</option>
        </select>
        <select class="input" name="audience">
            <option value="">All audiences</option>
            <?php $__currentLoopData = $audiences; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($key); ?>" <?php if(($audience ?? null) === $key): echo 'selected'; endif; ?>><?php echo e($label); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>
        <span class="qf-auto-filter-status" role="status" aria-live="polite"><span class="qf-auto-filter-spinner" aria-hidden="true"></span><span data-qf-filter-status>Updating…</span></span>
        <?php if($status !== null || $officeValue !== 'all' || ($audience ?? null) !== null): ?>
            <a href="<?php echo e(route('admin.announcements.index')); ?>" class="btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
    <a href="<?php echo e(route('admin.announcements.create')); ?>" class="btn-primary sm:ml-auto self-start">+ New Announcement</a>
</div>

<div class="card">
    <div class="table-wrap overflow-x-auto">
        <table class="admin-table min-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Posted By</th>
                    <th>From Office</th>
                    <th>Status</th>
                    <th>Audience</th>
                    <th>Student View</th>
                    <th>Created</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $announcements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php
                    [$statusBadgeClass, $statusBadgeText] = $statusBadges[$a->status]
                        ?? [$a->is_active ? 'badge-served' : 'badge-cancelled', $a->is_active ? 'Visible' : 'Hidden'];
                ?>
                <tr>
                    <td class="min-w-0 max-w-[200px]">
                        <div class="font-semibold truncate" title="<?php echo e($a->title); ?>"><?php echo e($a->title); ?></div>
                        <div class="text-xs text-slate-500 mt-1 truncate" title="<?php echo e($a->body); ?>"><?php echo e(\Illuminate\Support\Str::limit($a->body,40)); ?></div>
                        <?php if($a->status === \App\Models\Announcement::STATUS_PENDING && $a->submittedBy): ?>
                            <div class="text-[10px] mt-1 text-amber-600 font-semibold truncate" title="Requested by <?php echo e($a->submittedBy->name); ?>">Requested by <?php echo e($a->submittedBy->name); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($a->author->name ?? 'System'); ?></td>
                    <td>
                        <?php if($a->office): ?>
                            <span class="text-xs font-semibold"><?php echo e($a->office->name); ?></span>
                            <span class="text-[10px] text-slate-400 ml-1"><?php echo e($a->office->code); ?></span>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">Global</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo e($statusBadgeClass); ?>"><?php echo e($statusBadgeText); ?></span></td>
                    <td>
                        <span class="badge bg-slate-100 text-slate-600">
                            <?php echo e($a->audienceLabel()); ?>

                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo e($a->is_active ? 'badge-served' : 'bg-slate-100 text-slate-600'); ?>">
                            <?php echo e($a->is_active ? 'Visible' : 'Hidden'); ?>

                        </span>
                    </td>
                    <td><?php echo e($a->created_at->format('M d, Y')); ?></td>
                    <td class="text-right whitespace-nowrap">
                        <div class="flex justify-end items-center gap-1 flex-nowrap">
                            <?php if($a->status === \App\Models\Announcement::STATUS_PENDING): ?>
                                <form method="POST" action="<?php echo e(route('admin.announcements.approve',$a)); ?>" class="inline-flex"><?php echo csrf_field(); ?><button class="btn-primary text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Accept</button></form>
                                <form method="POST" action="<?php echo e(route('admin.announcements.reject',$a)); ?>" class="inline-flex" onsubmit="return confirm('Reject this announcement request?')"><?php echo csrf_field(); ?><button class="btn-danger text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Reject</button></form>
                            <?php endif; ?>
                            <a class="btn-secondary text-[10px] px-1.5 h-6 inline-flex items-center whitespace-nowrap leading-none" href="<?php echo e(route('admin.announcements.edit',$a)); ?>">Edit</a>
                            <form method="POST" action="<?php echo e(route('admin.announcements.destroy',$a)); ?>" class="inline-flex" onsubmit="return confirm('Delete this announcement?')"><?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?><button class="btn-danger text-[10px] px-1.5 h-6 whitespace-nowrap leading-none">Delete</button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr><td colspan="8" class="text-center text-slate-500 py-10">No announcements found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="p-4"><?php echo e($announcements->links()); ?></div>
</div>
<?php echo $__env->make('partials.auto-filter', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/announcements/index.blade.php ENDPATH**/ ?>