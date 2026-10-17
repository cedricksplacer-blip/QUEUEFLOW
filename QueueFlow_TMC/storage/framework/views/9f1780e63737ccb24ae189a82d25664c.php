
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag; ?>
<?php foreach($attributes->onlyProps(['user']) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
} ?>
<?php $attributes = $attributes->exceptProps(['user']); ?>
<?php foreach (array_filter((['user']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
} ?>
<?php $__defined_vars = get_defined_vars(); ?>
<?php foreach ($attributes as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
} ?>
<?php unset($__defined_vars); ?>

<?php if($user->hasAvatar()): ?>
    <img class="avatar-img" src="<?php echo e($user->avatarUrl()); ?>" alt="<?php echo e($user->name); ?>" loading="lazy">
<?php else: ?>
    <?php echo e(strtoupper(substr($user->name, 0, 1))); ?>

<?php endif; ?>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/components/avatar.blade.php ENDPATH**/ ?>