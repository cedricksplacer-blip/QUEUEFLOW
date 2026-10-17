<?php $__env->startSection('title','Offices'); ?>
<?php $__env->startSection('content'); ?>
<?php
    $deploymentBadges = [
        'open' => ['badge-serving', 'OPEN — accepting tickets'],
        'full' => ['badge-waiting', 'FULL — 100 slots reached'],
        'closed' => ['badge-cancelled', 'CLOSED'],
        'deployed' => ['badge-serving', 'DEPLOYED — processing'],
        'completed' => ['badge-served', 'COMPLETED'],
    ];
?>
<div class="flex justify-between items-center mb-5"><div><p class="text-slate-500 text-sm">Manage service locations, queue prefixes, schedule, and 100-slot batches.</p></div><a href="<?php echo e(route('admin.offices.create')); ?>" class="btn-primary">+ Add Office</a></div>
<div class="grid md:grid-cols-2 xl:grid-cols-2 gap-4"><?php $__empty_1 = true; $__currentLoopData = $offices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $office): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
<?php
    $deployment = $office->activeDeployment;
    $status = $deployment ? $deployment->status : null;
    $limit = $office->slotLimit();
    $used = $deployment ? $deployment->submittedCount() : 0;
    $pct = $limit ? round($used / $limit * 100) : 0;
    [$badgeClass, $badgeText] = $deploymentBadges[$status] ?? ['bg-slate-100 text-slate-600','No batch yet'];
?>
<div class="card p-5">
    <div class="flex justify-between gap-3">
        <div><h3 class="font-bold text-lg"><?php echo e($office->name); ?></h3><div class="text-xs font-bold text-blue-600 mt-1"><?php echo e($office->code); ?></div></div>
        <form method="POST" action="<?php echo e(route('admin.offices.toggle-active',$office)); ?>" onsubmit="return confirm('<?php echo e($office->is_active?'Deactivate':'Activate'); ?> <?php echo e($office->name); ?>?')"><?php echo csrf_field(); ?>
            <button class="btn-<?php echo e($office->is_active?'danger':'primary'); ?>" style="height:24px;min-height:24px;padding:0 8px;font-size:10px">
                <?php echo e($office->is_active?'Turn Off':'Turn On'); ?>

            </button>
        </form>
    </div>
    <p class="text-sm text-slate-500 mt-3 min-h-6"><?php echo e($office->description ?: 'No description provided.'); ?></p>
    <?php if($office->office_type || $office->location): ?>
        <p class="text-xs text-slate-500 mt-1"><?php echo e(implode(' · ', array_filter([$office->office_type, $office->location]))); ?></p>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Schedule</div><div class="font-bold text-sm mt-1"><?php echo e($office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : '—'); ?> – <?php echo e($office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : '—'); ?></div></div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Max Slots / Batch</div><div class="font-bold text-sm mt-1"><?php echo e($limit); ?></div></div>
    </div>

    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-slate-500">Batch #<?php echo e($deployment->batch_no ?? '—'); ?></span>
            <?php if($status): ?><span class="badge <?php echo e($badgeClass); ?>"><?php echo e($badgeText); ?></span><?php else: ?><span class="badge bg-slate-100 text-slate-600">No batch yet</span><?php endif; ?>
        </div>
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1"><span><?php echo e($used); ?> / <?php echo e($limit); ?> slots used</span><span><?php if($deployment && $deployment->servedCount() > 0): ?> <?php echo e($deployment->servedCount()); ?> served <?php endif; ?></span></div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full <?php echo e($pct >= 100 ? 'bg-amber-500' : 'bg-blue-500'); ?>" style="width:<?php echo e($pct); ?>%"></div></div>
    </div>

    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-slate-500">Stations &amp; Daily Capacity</span>
            <?php if(in_array($office->code, \App\Models\Office::OPERATIONAL_CODES, true)): ?><a href="<?php echo e(route('admin.offices.stations.create',$office)); ?>" class="text-[11px] font-bold text-blue-600 hover:underline">+ Add Station</a><?php endif; ?>
        </div>
        <div class="space-y-2">
        <?php $__empty_2 = true; $__currentLoopData = $office->stations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $station): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_2 = false; ?>
            <div class="flex items-center justify-between gap-3 text-xs">
                <div>
                    <div class="font-semibold text-slate-700"><?php echo e($station->name); ?></div>
                    <div class="text-slate-400"><?php echo e($station->location ?: 'No location set'); ?></div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="badge bg-blue-50 text-blue-700"><?php echo e($station->min_daily_students); ?>–<?php echo e($station->target_daily_students); ?>/day · <?php echo e($station->max_daily_slots); ?> slots</span>
                    <a href="<?php echo e(route('admin.offices.stations.edit',[$office,$station])); ?>" class="text-[11px] font-bold text-blue-600 hover:underline">Edit</a>
                    <form method="POST" action="<?php echo e(route('admin.offices.stations.destroy',[$office,$station])); ?>" onsubmit="return confirm('Remove this station?')"><?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?><button class="text-[11px] font-bold text-red-600 hover:underline">Delete</button></form>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_2): ?>
            <div class="text-[11px] text-slate-400">No stations yet.</div>
        <?php endif; ?>
        </div>
    </div>
    <div class="rounded-xl border border-slate-100 mt-4 p-3">
        <div class="text-xs font-semibold text-slate-500 mb-2">Services</div>
        <div class="flex flex-wrap gap-1.5"><?php $__currentLoopData = $office->services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><span class="badge bg-slate-100 text-slate-600"><?php echo e($service->name); ?></span><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></div>
    </div>

    <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-slate-500">Staff</div><div class="font-bold text-lg"><?php echo e($office->staff->count()); ?></div>
            <?php $__empty_2 = true; $__currentLoopData = $office->staff; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $member): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_2 = false; ?>
                <div class="flex items-center gap-2 mt-1.5">
                    <span class="flex h-6 w-6 items-center justify-center overflow-hidden rounded-md text-[10px] font-bold text-white" style="background:linear-gradient(135deg,#1E6A52,#16A37A)"><?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => $member]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($member)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $attributes = $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $component = $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?></span>
                    <span class="text-xs font-semibold text-slate-700"><?php echo e($member->name); ?></span>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_2): ?>
                <div class="text-[11px] text-slate-400 mt-1.5">No staff assigned</div>
            <?php endif; ?>
        </div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Tickets</div><div class="font-bold text-lg"><?php echo e($office->queue_tickets_count); ?></div></div>
    </div>

    <div class="flex flex-wrap gap-2 mt-4">
        <?php if($status === 'open' || $status === 'full'): ?>
        <form method="POST" action="<?php echo e(route('admin.offices.close-deployment',$office)); ?>" onsubmit="return confirm('Are you sure you want to reset the batch number? The current batch will be closed and sent to history, and a new batch will start at #1.')"><?php echo csrf_field(); ?><button class="btn-secondary text-xs">Close & Reset Batch</button></form>
        <?php endif; ?>
        <?php if($status === 'deployed'): ?>
        <form method="POST" action="<?php echo e(route('admin.offices.complete-deployment',$office)); ?>" onsubmit="return confirm('Mark batch as completed and open a new 100-slot batch?')"><?php echo csrf_field(); ?><button class="btn-secondary text-xs">Complete & Start New Batch</button></form>
        <?php endif; ?>
    </div>

    <div class="flex gap-2 mt-4">
        <a class="btn-secondary flex-1" href="<?php echo e(route('admin.offices.edit',$office)); ?>">Settings</a>
        <form class="flex-1" method="POST" action="<?php echo e(route('admin.offices.destroy',$office)); ?>" onsubmit="return confirm('Delete this office?')"><?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?><button class="btn-danger w-full">Delete</button></form>
    </div>
</div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><div class="card p-10 text-center text-slate-500 md:col-span-2 xl:col-span-2">No offices found.</div><?php endif; ?></div><div class="card mt-5"><div class="p-4"><?php echo e($offices->links('vendor.pagination.queueflow')); ?></div></div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/offices/index.blade.php ENDPATH**/ ?>