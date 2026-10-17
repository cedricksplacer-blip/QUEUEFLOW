
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag; ?>
<?php foreach($attributes->onlyProps([
    'name',
    'checked' => false,
    'label' => '',
    'on' => 'Active',
    'off' => 'Inactive',
]) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
} ?>
<?php $attributes = $attributes->exceptProps([
    'name',
    'checked' => false,
    'label' => '',
    'on' => 'Active',
    'off' => 'Inactive',
]); ?>
<?php foreach (array_filter(([
    'name',
    'checked' => false,
    'label' => '',
    'on' => 'Active',
    'off' => 'Inactive',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
} ?>
<?php $__defined_vars = get_defined_vars(); ?>
<?php foreach ($attributes as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
} ?>
<?php unset($__defined_vars); ?>

<label <?php echo e($attributes->class('toggle')); ?>>
    <input type="checkbox" name="<?php echo e($name); ?>" value="1" <?php if($checked): echo 'checked'; endif; ?>>
    <span class="toggle-track" aria-hidden="true"></span>
    <span class="toggle-text">
        <span class="toggle-title"><?php echo e($label); ?></span>
        <span class="toggle-state">
            <span class="toggle-on"><?php echo e($on); ?></span>
            <span class="toggle-off"><?php echo e($off); ?></span>
        </span>
    </span>
</label>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/components/toggle.blade.php ENDPATH**/ ?>