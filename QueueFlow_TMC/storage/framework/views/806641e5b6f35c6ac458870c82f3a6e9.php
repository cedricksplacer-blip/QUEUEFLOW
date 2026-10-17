<?php $__env->startSection('title', 'My Profile'); ?>

<?php $__env->startSection('content'); ?>

<style>
    /* Small additions; the admin layout already provides .card, .input,
       .btn-primary, .btn-secondary, .badge-* and global input styling. */
    .profile-header-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: #8492a8;
        margin-bottom: 4px;
    }
    .profile-header-title { font-size: 1.6rem; font-weight: 800; color: #172033; }
    .profile-header-desc { color: #718096; font-size: .9rem; margin-top: 4px; }
    .profile-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #e8edf3;
        border-radius: 13px;
        padding: 8px 14px;
        font-size: .8rem;
        font-weight: 600;
        color: #526176;
    }
    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 9999px;
        background: #16a34a;
        display: inline-block;
    }
    .profile-alert {
        border-radius: 14px;
        padding: 12px 16px;
        font-size: .875rem;
        margin-bottom: 16px;
    }
    .profile-alert.success { background: #e6f6ec; color: #166534; }
    .profile-alert.error { background: #fdeaea; color: #991b1b; }
    .profile-card-header { border-bottom: 1px solid rgba(163,177,198,.28); }
    .profile-card-header h2 { font-size: 1.05rem; font-weight: 700; color: #172033; }
    .profile-card-header p { color: #718096; font-size: .8rem; margin-top: 2px; }
    .profile-label {
        display: block;
        font-size: .75rem;
        font-weight: 700;
        color: #526176;
        margin-bottom: 6px;
    }
    .profile-input {
        width: 100%;
        font-size: .875rem;
    }
    .profile-input.input-error { border-color: #dc4444 !important; }
    .profile-error { color: #b91c1c; font-size: .75rem; margin-top: 6px; }
    html.dark .profile-alert.success { background: #14301f; color: #86efac; }
    html.dark .profile-alert.error { background: #3a1a1a; color: #fca5a5; }
    html.dark .profile-card-header h2 { color: #eef4fb; }
</style>





<div class="card mb-5">

    <div class="p-6 flex flex-wrap items-center justify-between gap-4">

        <div>
            <div class="profile-header-label">TMC ADMINISTRATION</div>
            <h1 class="profile-header-title">My Profile</h1>
            <p class="profile-header-desc">
                Manage your own account details, contact information, and password.
            </p>
        </div>

        <div class="profile-status">
            <span class="status-dot"></span>
            <span><?php echo e(ucfirst($user->role)); ?> Account</span>
        </div>

    </div>

</div>






<?php if(session('status')): ?>

    <div class="profile-alert success">
        <?php echo e(match (session('status')) {
            'profile-updated' => 'Profile updated successfully.',
            'avatar-removed' => 'Profile photo removed.',
            'password-updated' => 'Password updated successfully.',
            default => session('status'),
        }); ?>

    </div>

<?php elseif($errors->any()): ?>

    <div class="profile-alert error">
        <div class="font-semibold mb-1">Please correct the following:</div>
        <ul class="list-disc list-inside space-y-0.5">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>

<?php endif; ?>






<div class="card mb-5">

    <div class="p-6 flex items-center gap-4">

        <div class="user-avatar" style="width:80px;height:80px;font-size:2rem;flex-shrink:0;">
            <?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => $user]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $attributes = $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $component = $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
        </div>

        <div>
            <div class="text-lg font-bold text-slate-800"><?php echo e($user->name); ?></div>
            <div class="text-sm text-slate-500"><?php echo e($user->email); ?></div>
            <div class="mt-1 flex items-center gap-2">
                <span class="badge badge-served"><?php echo e(ucfirst($user->role)); ?></span>
                <?php if(! $user->is_active): ?>
                    <span class="badge badge-cancelled">Deactivated</span>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>






<div class="card mb-5">

    <div class="profile-card-header p-6">
        <h2>Profile Information</h2>
        <p>Update your name, email address, and contact number.</p>
    </div>

    <form method="POST" action="<?php echo e(route('profile.update')); ?>" enctype="multipart/form-data">

        <?php echo csrf_field(); ?>
        <?php echo method_field('PATCH'); ?>

        
        <input type="hidden" name="redirect_to" value="admin">

        <div class="p-6 space-y-5">

            <div>
                <label class="profile-label" for="profile_name">Name</label>
                <input
                    class="input profile-input <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> input-error <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    id="profile_name"
                    name="name"
                    type="text"
                    value="<?php echo e(old('name', $user->name)); ?>"
                    required
                    autocomplete="name"
                >
                <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <p class="profile-error"><?php echo e($message); ?></p>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div>
                <label class="profile-label" for="profile_email">Email Address</label>
                <input
                    class="input profile-input <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> input-error <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    id="profile_email"
                    name="email"
                    type="email"
                    value="<?php echo e(old('email', $user->email)); ?>"
                    required
                    autocomplete="email"
                >
                <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <p class="profile-error"><?php echo e($message); ?></p>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div>
                <label class="profile-label" for="profile_phone">
                    Contact Number <span class="text-slate-400">(optional)</span>
                </label>
                <input
                    class="input profile-input <?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> input-error <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    id="profile_phone"
                    name="phone"
                    type="text"
                    value="<?php echo e(old('phone', $user->phone)); ?>"
                    autocomplete="tel"
                >
                <?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <p class="profile-error"><?php echo e($message); ?></p>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div>
                <label class="profile-label" for="profile_avatar">Profile Photo</label>

                <div class="flex items-center gap-4">

                    <div class="user-avatar" style="width:64px;height:64px;font-size:1.6rem;flex-shrink:0;">
                        <?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => $user]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($user)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $attributes = $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $component = $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
                    </div>

                    <div class="flex-1">
                        <input
                            class="input profile-input"
                            id="profile_avatar"
                            name="avatar"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >
                        <p class="text-xs text-slate-500 mt-1">
                            JPG, JPEG, PNG or WEBP only. Max 2 MB. Leaving this empty keeps your current photo.
                        </p>

                        <?php $__errorArgs = ['avatar'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <p class="profile-error"><?php echo e($message); ?></p>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

                        <button
                            type="button"
                            id="viewProfilePhoto"
                            class="btn-secondary mt-3 text-sm"
                            data-photo-url="<?php echo e($user->hasAvatar() ? $user->avatarUrl() : ''); ?>"
                            data-user-name="<?php echo e($user->name); ?>"
                        >
                            View Profile Picture
                        </button>
                    </div>

                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="<?php echo e(route('admin.settings.index')); ?>" class="btn-secondary">Back to Settings</a>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>

        </div>

    </form>

</div>






<div class="card">

    <div class="profile-card-header p-6">
        <h2>Change Password</h2>
        <p>Choose a new password to keep your account secure.</p>
    </div>

    <form method="POST" action="<?php echo e(route('password.update')); ?>">

        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>

        <div class="p-6 space-y-5">

            <div>
                <label class="profile-label" for="current_password">Current Password</label>
                <input
                    class="input profile-input <?php $__errorArgs = ['current_password', 'updatePassword'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> input-error <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    id="current_password"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                >
                <?php $__errorArgs = ['current_password', 'updatePassword'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <p class="profile-error"><?php echo e($message); ?></p>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div>
                <label class="profile-label" for="new_password">New Password</label>
                <input
                    class="input profile-input <?php $__errorArgs = ['password', 'updatePassword'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> input-error <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    id="new_password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                >
                <?php $__errorArgs = ['password', 'updatePassword'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <p class="profile-error"><?php echo e($message); ?></p>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div>
                <label class="profile-label" for="new_password_confirmation">Confirm New Password</label>
                <input
                    class="input profile-input"
                    id="new_password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                >
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary">Update Password</button>
            </div>

        </div>

    </form>

</div>






<style>
    .qf-photo-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, .62); padding: 16px; }
    .qf-photo-overlay[hidden] { display: none !important; }
    .qf-photo-card { width: 100%; max-width: 520px; max-height: 92vh; overflow: auto; border-radius: 14px; background: #fff; box-shadow: 0 24px 48px rgba(0, 0, 0, .35); }
    .qf-photo-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
    .qf-photo-title { font-size: 14px; font-weight: 800; color: #0f172a; }
    .qf-photo-close { flex-shrink: 0; width: 30px; height: 30px; border-radius: 9999px; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-size: 16px; line-height: 1; cursor: pointer; }
    .qf-photo-close:hover { background: #f1f5f9; }
    .qf-photo-body { padding: 16px; text-align: center; background: #f8fafc; }
    .qf-photo-body img { max-width: 100%; max-height: 62vh; width: auto; height: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    .qf-photo-body img[hidden] { display: none !important; }
    .qf-photo-empty { margin: 0; padding: 22px 12px; font-size: 12px; color: #94a3b8; }
    .qf-photo-empty[hidden] { display: none !important; }
    .qf-photo-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px 16px; }
    html.dark .qf-photo-card { background: #1e293b; }
    html.dark .qf-photo-head { border-color: #334155; }
    html.dark .qf-photo-title { color: #f8fafc; }
    html.dark .qf-photo-close { background: #0b1120; border-color: #334155; color: #e2e8f0; }
    html.dark .qf-photo-body { background: #0b1120; }
    html.dark .qf-photo-body img { border-color: #334155; }
</style>
<div id="profilePhotoModal" class="qf-photo-overlay" hidden>
    <div class="qf-photo-card" role="dialog" aria-modal="true" aria-label="Profile picture viewer">
        <div class="qf-photo-head">
            <div class="qf-photo-title" id="profilePhotoTitle">Profile Picture</div>
            <button type="button" id="profilePhotoClose" class="qf-photo-close" aria-label="Close viewer">×</button>
        </div>
        <div class="qf-photo-body">
            <img id="profilePhotoImg" alt="Profile picture" hidden>
            <p id="profilePhotoEmpty" class="qf-photo-empty" hidden>No profile picture uploaded yet.</p>
        </div>
        <div class="qf-photo-actions">
            <button type="button" id="profilePhotoDone" class="btn-secondary text-sm">Close</button>
        </div>
    </div>
</div>
<script>
(function () {
    var trigger = document.getElementById('viewProfilePhoto');
    var modal = document.getElementById('profilePhotoModal');
    if (!trigger || !modal || modal.dataset.qfBound === '1') { return; }
    modal.dataset.qfBound = '1';

    var img = document.getElementById('profilePhotoImg');
    var empty = document.getElementById('profilePhotoEmpty');
    var title = document.getElementById('profilePhotoTitle');

    function close() {
        modal.hidden = true;
        img.removeAttribute('src');
        document.body.classList.remove('overflow-hidden');
    }

    img.addEventListener('load', function () {
        empty.hidden = true;
        img.hidden = false;
    });

    img.addEventListener('error', function () {
        img.hidden = true;
        empty.hidden = false;
    });

    document.getElementById('profilePhotoClose').addEventListener('click', close);
    document.getElementById('profilePhotoDone').addEventListener('click', close);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) { close(); }
    });

    trigger.addEventListener('click', function () {
        var url = trigger.getAttribute('data-photo-url') || '';
        var name = trigger.getAttribute('data-user-name') || '';
        title.textContent = name ? name + ' — Profile Picture' : 'Profile Picture';

        if (!url) {
            img.hidden = true;
            empty.hidden = false;
        } else {
            empty.hidden = true;
            img.hidden = true;
        }

        modal.hidden = false;
        document.body.classList.add('overflow-hidden');

        if (url) { img.src = url; }
    });
})();
</script>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/admin/profile.blade.php ENDPATH**/ ?>