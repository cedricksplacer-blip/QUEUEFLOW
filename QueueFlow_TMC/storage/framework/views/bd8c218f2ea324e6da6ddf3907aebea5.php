<?php $__env->startSection('title','Edit Announcement'); ?>
<?php $__env->startSection('content'); ?>
<div class="max-w-3xl card p-6"><form method="POST" action="<?php echo e(route('admin.announcements.update',$announcement)); ?>" class="space-y-5"><?php echo csrf_field(); ?> <?php echo method_field('PUT'); ?>
<div><label class="block text-sm font-semibold mb-1.5">Title</label><input class="input" name="title" value="<?php echo e(old('title',$announcement->title)); ?>" required></div>
<div><label class="block text-sm font-semibold mb-1.5">Message</label><textarea class="input" name="body" rows="7" required><?php echo e(old('body',$announcement->body)); ?></textarea></div>
<div><label class="block text-sm font-semibold mb-1.5">Audience</label><select class="input" name="audience" required><option value="user" <?php if(old('audience', $announcement->audience ?? 'both') === 'user'): echo 'selected'; endif; ?>>User Only</option><option value="staff" <?php if(old('audience', $announcement->audience ?? 'both') === 'staff'): echo 'selected'; endif; ?>>Staff Only</option><option value="both" <?php if(old('audience', $announcement->audience ?? 'both') === 'both'): echo 'selected'; endif; ?>>User and Staff</option></select></div>
<?php if (isset($component)) { $__componentOriginal592735d30e1926fbb04ff9e089d1fccf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal592735d30e1926fbb04ff9e089d1fccf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.toggle','data' => ['name' => 'is_active','checked' => (bool) old('is_active',$announcement->is_active),'label' => 'Visible to students','on' => 'Visible on the student portal','off' => 'Hidden from students']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('toggle'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'is_active','checked' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute((bool) old('is_active',$announcement->is_active)),'label' => 'Visible to students','on' => 'Visible on the student portal','off' => 'Hidden from students']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal592735d30e1926fbb04ff9e089d1fccf)): ?>
<?php $attributes = $__attributesOriginal592735d30e1926fbb04ff9e089d1fccf; ?>
<?php unset($__attributesOriginal592735d30e1926fbb04ff9e089d1fccf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal592735d30e1926fbb04ff9e089d1fccf)): ?>
<?php $component = $__componentOriginal592735d30e1926fbb04ff9e089d1fccf; ?>
<?php unset($__componentOriginal592735d30e1926fbb04ff9e089d1fccf); ?>
<?php endif; ?>
<div class="flex gap-2"><button class="btn-primary">Save Changes</button><a class="btn-secondary" href="<?php echo e(route('admin.announcements.index')); ?>">Cancel</a></div></form></div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/announcements/edit.blade.php ENDPATH**/ ?>