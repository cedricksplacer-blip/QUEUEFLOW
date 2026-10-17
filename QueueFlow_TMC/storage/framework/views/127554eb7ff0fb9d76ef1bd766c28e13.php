<?php $__env->startSection('title', 'Staff Settings'); ?>

<?php $__env->startSection('content'); ?>

<style>
.qf-switch { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.qf-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.qf-switch .track {
    width: 44px; height: 24px; border-radius: 9999px;
    background: #cbd5e1;
    box-shadow: none;
    transition: background .18s ease; display: inline-block; position: relative;
}
.qf-switch .track::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15,23,42,.20);
    transition: transform .18s ease;
}
.qf-switch input:checked + .track { background: #2563eb; box-shadow: none; }
.qf-switch input:checked + .track::after { transform: translateX(20px); }
html.dark .qf-switch .track { background: #334155; box-shadow: none; }
html.dark .qf-switch input:checked + .track { background: #2563eb; }

/* Preference toggle row text — dark and readable in light mode */
.qf-dashboard label .text-slate-800 { color: #0f172a !important; }
.qf-dashboard label .text-slate-600 { color: #334155 !important; }
.qf-dashboard label .text-slate-500 { color: #475569 !important; }
.qf-dashboard label .text-slate-400 { color: #475569 !important; }

/* Dark mode: preference toggle text stays dark */
html.dark .qf-dashboard label .text-slate-800 { color: #1e293b !important; }
html.dark .qf-dashboard label .text-slate-600 { color: #334155 !important; }
html.dark .qf-dashboard label .text-slate-500 { color: #475569 !important; }
html.dark .qf-dashboard label .text-slate-400 { color: #475569 !important; }
html.dark .qf-dashboard label.p-3,
html.dark .qf-dashboard label.p-3:hover { background: #f8fafc !important; }
html.dark .qf-dashboard label.p-3 { border-color: #e2e8f0 !important; }
html.dark .qf-dashboard label.p-3:hover { border-color: #bfdbfe !important; }

/* Dark mode: Change Password — dark text on light cells */
html.dark .qf-dashboard form .grid > div {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    padding: 12px !important;
}
html.dark .qf-dashboard form .grid > div label { color: #334155 !important; }
html.dark .qf-dashboard form .grid > div .input,
html.dark .qf-dashboard form .grid > div input[type="password"] {
    background: #ffffff !important;
    background-image: none !important;
    color: #0f172a !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: none !important;
}
html.dark .qf-dashboard form .grid > div input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, .25) !important;
}
html.dark .qf-dashboard label .hover\:border-blue-200:hover { border-color: rgba(59, 130, 246, .30) !important; }
html.dark .qf-dashboard label .border-slate-100 { border-color: rgba(129, 140, 248, .25) !important; }
</style>

<div class="qf-dashboard w-full max-w-[900px] mx-auto px-3 sm:px-4 min-w-0">

    <div class="qf-reveal mb-5">
        <h3 class="text-sm font-bold text-slate-900">Staff Settings</h3>
        <p class="text-xs text-slate-500 mt-1">
            Preferences for your <?php echo e($office->name); ?> console. These only apply to your account.
        </p>
    </div>

    

    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">

        <div class="p-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Console Preferences</h3>
            <p class="text-[11px] text-slate-500 mt-1">Appearance and queue-console behaviour.</p>
        </div>

        <form method="POST" action="<?php echo e(route('staff.settings.update')); ?>">
            <?php echo csrf_field(); ?>

            <div class="p-4 space-y-4">

                <label class="flex items-center justify-between gap-4 cursor-pointer p-3 rounded-xl border border-slate-100 hover:border-blue-200 transition">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800">Dark mode</div>
                        <div class="text-xs text-slate-500 mt-0.5">Use the dark theme for your staff console only. Does not change the rest of the system for other users.</div>
                    </div>
                    <span class="qf-switch flex-shrink-0">
                        <input type="checkbox" name="dark_mode" value="1" <?php if($preferences['dark_mode'] === '1'): echo 'checked'; endif; ?> onchange="document.documentElement.classList.toggle('dark', this.checked); this.form.submit();">
                        <span class="track"></span>
                    </span>
                </label>

                <label class="flex items-center justify-between gap-4 cursor-pointer p-3 rounded-xl border border-slate-100 hover:border-blue-200 transition">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800">Auto-call next student</div>
                        <div class="text-xs text-slate-500 mt-0.5">After you mark a student as served, the next student in line is called automatically.</div>
                    </div>
                    <span class="qf-switch flex-shrink-0">
                        <input type="checkbox" name="auto_call" value="1" <?php if($preferences['auto_call'] === '1'): echo 'checked'; endif; ?> onchange="this.form.submit();">
                        <span class="track"></span>
                    </span>
                </label>

                <label class="flex items-center justify-between gap-4 cursor-pointer p-3 rounded-xl border border-slate-100 hover:border-blue-200 transition">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800">Show student names in line</div>
                        <div class="text-xs text-slate-500 mt-0.5">Display student names on the waiting list. Turn off to show ticket numbers only.</div>
                    </div>
                    <span class="qf-switch flex-shrink-0">
                        <input type="checkbox" name="show_names" value="1" <?php if($preferences['show_names'] === '1'): echo 'checked'; endif; ?> onchange="this.form.submit();">
                        <span class="track"></span>
                    </span>
                </label>

                <label class="flex items-center justify-between gap-4 cursor-pointer p-3 rounded-xl border border-slate-100 hover:border-blue-200 transition">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800">Confirm before marking served</div>
                        <div class="text-xs text-slate-500 mt-0.5">Ask for confirmation before a student is marked as served. Helps avoid accidental taps.</div>
                    </div>
                    <span class="qf-switch flex-shrink-0">
                        <input type="checkbox" name="confirm_served" value="1" <?php if($preferences['confirm_served'] === '1'): echo 'checked'; endif; ?> onchange="this.form.submit();">
                        <span class="track"></span>
                    </span>
                </label>

            </div>

            <div class="p-4 pt-0 border-t border-slate-100">
                <button type="submit" class="btn-primary text-xs">Save Preferences</button>
            </div>

        </form>

        <div class="p-4 pt-0 mt-4">
            <form method="POST" action="<?php echo e(route('staff.settings.reset')); ?>" onsubmit="return confirm('Reset all of your preferences to their defaults?');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn-secondary text-xs">Reset to Defaults</button>
            </form>
        </div>

    </div>

    

    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">

        <div class="p-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Change Password</h3>
            <p class="text-[11px] text-slate-500 mt-1">Update the password you use to sign in to your staff account.</p>
        </div>

        <form method="POST" action="<?php echo e(route('staff.settings.password.update')); ?>" class="p-4 space-y-4">
            <?php echo csrf_field(); ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-slate-400 font-bold block mb-1" for="current_password">Current password</label>
                    <input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password" style="width:100%">
                </div>
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-slate-400 font-bold block mb-1" for="password">New password</label>
                    <input class="input" id="password" type="password" name="password" required autocomplete="new-password" style="width:100%">
                </div>
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-slate-400 font-bold block mb-1" for="password_confirmation">Confirm new password</label>
                    <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" style="width:100%">
                </div>
            </div>

            <div>
                <button type="submit" class="btn-primary text-xs">Update Password</button>
            </div>

        </form>

    </div>

    

    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden">

        <div class="p-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Your Account</h3>
            <p class="text-[11px] text-slate-500 mt-1">Current staff session details.</p>
        </div>

        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                    <span class="w-20 flex-shrink-0 text-[11px] uppercase tracking-wide text-slate-400 font-bold">Name</span>
                    <strong class="text-sm text-slate-900 min-w-0 truncate"><?php echo e($user->name); ?></strong>
                </div>
                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                    <span class="w-20 flex-shrink-0 text-[11px] uppercase tracking-wide text-slate-400 font-bold">Email</span>
                    <strong class="text-sm text-slate-900 min-w-0 truncate"><?php echo e($user->email); ?></strong>
                </div>
                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                    <span class="w-20 flex-shrink-0 text-[11px] uppercase tracking-wide text-slate-400 font-bold">Office</span>
                    <strong class="text-sm text-slate-900 min-w-0 truncate"><?php echo e($office->name); ?></strong>
                </div>
                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                    <span class="w-20 flex-shrink-0 text-[11px] uppercase tracking-wide text-slate-400 font-bold">Role</span>
                    <strong class="text-sm text-slate-900 min-w-0 capitalize"><?php echo e($user->role); ?></strong>
                </div>
            </div>
        </div>

    </div>

</div>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/settings/index.blade.php ENDPATH**/ ?>