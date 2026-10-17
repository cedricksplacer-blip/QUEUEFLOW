<?php
    $darkMode = \App\Models\Setting::get('dark_mode', '0') === '1';
    $admin = auth()->user();
    $initials = strtoupper(substr($admin->name ?? 'A', 0, 1));
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo e($darkMode ? 'dark' : ''); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'Admin'); ?> - QueueFlow TMC</title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo $__env->yieldPushContent('styles'); ?>
</head>

<body>
<div class="admin-shell">

    <!-- =========================================================
         SIDEBAR
    ========================================================= -->
    <aside class="admin-sidebar" id="qfSidebar" aria-label="Administration navigation">

        <div class="sidebar-logo">
            <a href="<?php echo e(route('admin.dashboard')); ?>">
                <span class="logo-box">
                    <img src="<?php echo e(asset('images/L.png')); ?>" alt="Trinidad Municipal College seal">
                </span>
                <span>
                    <span class="logo-title">QueueFlow</span>
                    <span class="logo-subtitle">TMC Administration</span>
                </span>
            </a>
        </div>

        <nav class="sidebar-nav">
            <?php ($adminBadges = \App\Support\SidebarBadges::admin()); ?>

            <div class="sidebar-section">
                <div class="sidebar-label">Overview</div>
                <div class="sidebar-links">
                    <a href="<?php echo e(route('admin.dashboard')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.dashboard') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg>
                        <span>Dashboard</span>
                        <span class="nav-badge nav-badge-blue" data-sidebar-count="waiting_today" <?php if(($adminBadges['waiting_today'] ?? 0) <= 0): ?> hidden <?php endif; ?>><?php echo e(\App\Support\SidebarBadges::fmt($adminBadges['waiting_today'] ?? 0)); ?></span>
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label">Management</div>
                <div class="sidebar-links">
                    <a href="<?php echo e(route('admin.staff.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.staff.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 19 7a4 4 0 0 1-3 3.87"/></svg>
                        <span>Staff</span>
                    </a>

                    <a href="<?php echo e(route('admin.users.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.users.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                        <span>Users</span>
                    </a>

                    <a href="<?php echo e(route('admin.offices.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.offices.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-5h6v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                        <span>Offices</span>
                    </a>

                    <a href="<?php echo e(route('admin.notifications.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.notifications.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        <span>Cancellation Alerts</span>
                        <span class="nav-badge nav-badge-red" data-sidebar-count="alerts_unread" <?php if(($adminBadges['alerts_unread'] ?? 0) <= 0): ?> hidden <?php endif; ?>><?php echo e(\App\Support\SidebarBadges::fmt($adminBadges['alerts_unread'] ?? 0)); ?></span>
                    </a>

                    <a href="<?php echo e(route('admin.activity.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.activity.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                        <span>Queue Activity</span>
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label">System</div>
                <div class="sidebar-links">
                    <a href="<?php echo e(route('admin.announcements.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.announcements.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-8-8 18-2-8-8-2Z"/></svg>
                        <span>Announcements</span>
                        <span class="nav-badge nav-badge-amber" data-sidebar-count="announcements_pending" <?php if(($adminBadges['announcements_pending'] ?? 0) <= 0): ?> hidden <?php endif; ?>><?php echo e(\App\Support\SidebarBadges::fmt($adminBadges['announcements_pending'] ?? 0)); ?></span>
                    </a>

                    <a href="<?php echo e(route('admin.reports.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.reports.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/></svg>
                        <span>Reports</span>
                    </a>

                    <a href="<?php echo e(route('admin.settings.index')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.settings.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
                        <span>Settings</span>
                    </a>

                    <a href="<?php echo e(route('admin.profile.edit')); ?>"
                       class="nav-item <?php echo e(request()->routeIs('admin.profile.*') ? 'nav-active' : ''); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        <span>My Profile</span>
                    </a>
                </div>
            </div>
        </nav>

        <div class="sidebar-user-area">
            <div class="sidebar-user">
                <a href="<?php echo e(route('admin.profile.edit')); ?>" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;text-decoration:none;">
                    <span class="user-avatar"><?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => $admin]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($admin)]); ?>
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
                    <span class="user-info">
                        <span class="user-name" style="display:block;"><?php echo e($admin->name); ?></span>
                        <span class="user-role" style="display:block;">Administrator</span>
                    </span>
                </a>
                <button type="button" id="logoutButton" class="logout-button">Logout</button>
            </div>
        </div>
    </aside>

    <!-- =========================================================
         MAIN
    ========================================================= -->
    <main class="admin-main">

        <header class="admin-header">
            <div class="header-inner">
                <div class="header-left">
                    <button type="button" class="qf-menu-toggle" id="qfMenuToggle" aria-label="Open navigation" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div style="min-width:0;">
                        <div class="qf-breadcrumbs">
                            <a href="<?php echo e(route('admin.dashboard')); ?>">Admin</a>
                            <span class="sep">/</span>
                            <span class="current"><?php echo $__env->yieldContent('title', 'Dashboard'); ?></span>
                        </div>
                        <h1 class="header-title"><?php echo $__env->yieldContent('title', 'Dashboard'); ?></h1>
                    </div>
                </div>

                <div class="header-right">
                    <span class="header-date">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <?php echo e(now()->format('M d, Y')); ?>

                    </span>
                    <a href="<?php echo e(route('admin.profile.edit')); ?>" class="header-avatar" title="<?php echo e($admin->name); ?>">
                        <?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => $admin]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($admin)]); ?>
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
                    </a>
                </div>
            </div>
        </header>

        <div class="admin-page">

            <?php if(session('status')): ?>
                <div class="admin-alert qf-alert-success" style="margin-bottom:14px;"><?php echo e(session('status')); ?></div>
            <?php endif; ?>
            <?php if(session('success')): ?>
                <div class="admin-alert qf-alert-success" style="margin-bottom:14px;"><?php echo e(session('success')); ?></div>
            <?php endif; ?>
            <?php if(session('error')): ?>
                <div class="admin-alert qf-alert-error" style="margin-bottom:14px;"><?php echo e(session('error')); ?></div>
            <?php endif; ?>
            <?php if($errors->any()): ?>
                <div class="admin-alert qf-alert-error" style="margin-bottom:14px;">
                    <div style="font-weight:700;margin-bottom:4px;">Please correct the following:</div>
                    <ul style="list-style:disc;padding-left:18px;">
                        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($error); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php echo $__env->yieldContent('content'); ?>
        </div>
    </main>
</div>

<!-- Mobile drawer backdrop -->
<div class="qf-drawer-backdrop" id="qfDrawerBackdrop"></div>

<!-- =========================================================
     LOGOUT MODAL
========================================================= -->
<div id="logoutModal" class="qf-modal-overlay fixed inset-0 z-[9999] hidden items-center justify-center px-4">
    <div id="logoutModalContent" class="qf-modal-card w-full max-w-sm" style="padding:22px;">
        <div class="qf-modal-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M17 8l4 4m0 0l-4 4m4-4H7"/><path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/></svg>
        </div>
        <h2 class="qf-modal-title">Confirm logout</h2>
        <p class="qf-modal-text">You are about to sign out of the QueueFlow TMC administration portal.</p>
        <div class="qf-modal-actions">
            <button type="button" id="cancelLogout" class="qf-modal-cancel">Cancel</button>
            <form method="POST" action="<?php echo e(route('admin.logout')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="qf-modal-confirm">Logout</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ---- Mobile drawer ---- */
    var sidebar = document.getElementById('qfSidebar');
    var toggle = document.getElementById('qfMenuToggle');
    var backdrop = document.getElementById('qfDrawerBackdrop');

    function openDrawer() {
        document.body.classList.add('qf-drawer-open');
        if (backdrop) { backdrop.classList.add('is-open'); }
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
    }
    function closeDrawer() {
        document.body.classList.remove('qf-drawer-open');
        if (backdrop) { backdrop.classList.remove('is-open'); }
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    }
    if (toggle) { toggle.addEventListener('click', function () { document.body.classList.contains('qf-drawer-open') ? closeDrawer() : openDrawer(); }); }
    if (backdrop) { backdrop.addEventListener('click', closeDrawer); }
    if (sidebar) { sidebar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeDrawer); }); }
    window.addEventListener('resize', function () { if (window.innerWidth > 1023) { closeDrawer(); } });

    /* ---- Logout modal ---- */
    var logoutButton = document.getElementById('logoutButton');
    var logoutModal = document.getElementById('logoutModal');
    var cancelLogout = document.getElementById('cancelLogout');

    function closeLogoutModal() {
        if (!logoutModal) { return; }
        logoutModal.classList.add('hidden');
        logoutModal.classList.remove('flex');
        document.body.style.overflow = '';
    }
    if (logoutButton && logoutModal) {
        logoutButton.addEventListener('click', function () {
            logoutModal.classList.remove('hidden');
            logoutModal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        });
    }
    if (cancelLogout) { cancelLogout.addEventListener('click', closeLogoutModal); }
    if (logoutModal) { logoutModal.addEventListener('click', function (e) { if (e.target === logoutModal) { closeLogoutModal(); } }); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (logoutModal && !logoutModal.classList.contains('hidden')) { closeLogoutModal(); }
            closeDrawer();
        }
    });

    /* ---- Sidebar badges: live counts (unchanged behaviour) ---- */
    (function () {
        var badges = document.querySelectorAll('[data-sidebar-count]');
        if (!badges.length) { return; }
        function paint(counts) {
            badges.forEach(function (el) {
                var key = el.getAttribute('data-sidebar-count');
                if (!counts || typeof counts[key] === 'undefined') { return; }
                var n = parseInt(counts[key], 10) || 0;
                el.hidden = n <= 0;
                el.textContent = n > 99 ? '99+' : String(n);
            });
        }
        function refresh() {
            if (document.hidden) { return; }
            fetch('<?php echo e(route('admin.sidebar-counts')); ?>', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (r) { return r.ok ? r.json() : null; }).then(paint).catch(function () {});
        }
        setInterval(refresh, 30000);
    })();
});
</script>

<?php echo $__env->yieldPushContent('scripts'); ?>
</body>
</html>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/layouts/admin.blade.php ENDPATH**/ ?>