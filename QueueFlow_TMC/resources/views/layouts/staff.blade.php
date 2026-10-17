@php
    $userDarkPref = auth()->check() ? \App\Models\Setting::get('staff_' . auth()->id() . '_dark_mode') : null;
    $darkMode = $userDarkPref !== null
        ? $userDarkPref === '1'
        : \App\Models\Setting::get('dark_mode', '0') === '1';
@endphp
<!DOCTYPE html>
<html lang="en" class="{{ $darkMode ? 'dark' : '' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Staff') - QueueFlow TMC</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
@php
    $sidebarOffice = auth()->user()->office;
    $staffBadges = $sidebarOffice
        ? \App\Support\SidebarBadges::staff($sidebarOffice->id)
        : ['waiting_today' => 0, 'alerts_unread' => 0, 'pwd_waiting' => 0];
@endphp
<div class="min-h-screen">

    <aside class="staff-sidebar" id="qfStaffSidebar" aria-label="Staff navigation">

        <div class="staff-brand">
            <a class="staff-brand-link" href="{{ route('staff.dashboard') }}">
                <div class="staff-brand-logo"><img src="{{ asset('images/L.png') }}" alt="Trinidad Municipal College seal"></div>
                <div>
                    <div class="staff-brand-title">QueueFlow</div>
                    <div class="staff-brand-sub">Staff Portal</div>
                </div>
            </a>
        </div>

        <nav class="staff-nav">

            <div class="staff-nav-section">
                <div class="staff-nav-label">Main</div>
                <div class="staff-nav-links">
                    <a href="{{ route('staff.dashboard') }}" class="staff-nav-item {{ request()->routeIs('staff.dashboard') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg>
                        <span>Dashboard</span>
                        <span class="nav-badge nav-badge-blue" data-sidebar-count="waiting_today" @if(($staffBadges['waiting_today'] ?? 0) <= 0) hidden @endif>{{ \App\Support\SidebarBadges::fmt($staffBadges['waiting_today'] ?? 0) }}</span>
                    </a>
                </div>
            </div>

            <div class="staff-nav-section">
                <div class="staff-nav-label">Information</div>
                <div class="staff-nav-links">
                    <a href="{{ route('staff.announcements.index') }}" class="staff-nav-item {{ request()->routeIs('staff.announcements.*') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-8-8 18-2-8-8-2Z"/></svg>
                        <span>Announcements</span>
                    </a>
                    <a href="{{ route('staff.notifications.index') }}" class="staff-nav-item {{ request()->routeIs('staff.notifications.*') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        <span>Cancellation Alerts</span>
                        <span class="nav-badge nav-badge-red" data-sidebar-count="alerts_unread" @if(($staffBadges['alerts_unread'] ?? 0) <= 0) hidden @endif>{{ \App\Support\SidebarBadges::fmt($staffBadges['alerts_unread'] ?? 0) }}</span>
                    </a>
                    <a href="{{ route('staff.history.index') }}" class="staff-nav-item {{ request()->routeIs('staff.history.*') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                        <span>Queue History</span>
                    </a>
                    <a href="{{ route('staff.office.show') }}" class="staff-nav-item {{ request()->routeIs('staff.office.*') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-5h6v5"/><path d="M9 11h.01M15 11h.01"/></svg>
                        <span>My Office</span>
                    </a>
                </div>
            </div>

            <div class="staff-nav-section">
                <div class="staff-nav-label">Account</div>
                <div class="staff-nav-links">
                    <a href="{{ route('staff.settings.index') }}" class="staff-nav-item {{ request()->routeIs('staff.settings.*') ? 'nav-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
                        <span>Settings</span>
                    </a>
                </div>
            </div>

        </nav>

        <div class="staff-user">
            <div class="staff-user-box">
                <div class="staff-user-avatar"><x-avatar :user="auth()->user()" /></div>
                <div class="staff-user-info">
                    <div class="staff-user-name">{{ auth()->user()->name }}</div>
                    <div class="staff-user-office">{{ $sidebarOffice ? $sidebarOffice->name : 'No office assigned' }}</div>
                </div>
                <button type="button" id="logoutButton" class="staff-logout">Logout</button>
            </div>
        </div>

    </aside>

    <main class="staff-shell">
        <header class="admin-header">
            <div class="header-inner">
                <div class="header-left">
                    <button type="button" class="qf-menu-toggle" id="qfMenuToggle" aria-label="Open navigation" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div>
                        <div class="header-small-title">TMC Staff Office Queue</div>
                        <h1 class="header-title">@yield('title', 'Queue Console')</h1>
                    </div>
                </div>
                <div class="header-right">
                    {{-- Announcement bell: badge = unread admin announcements for the staff audience, panel polls below. --}}
                    <div class="qf-bell-wrap">
                        <button type="button" id="qfBellButton" class="qf-bell-button" aria-label="Announcements" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <span id="qfBellBadge" class="qf-bell-badge" hidden>0</span>
                        </button>
                        <div id="qfBellPanel" class="qf-bell-panel" hidden>
                            <div class="qf-bell-head">
                                <strong>Announcements</strong>
                                <span class="qf-bell-head-actions">
                                    <button type="button" id="qfBellReadAll" class="qf-bell-action" hidden>Mark all read</button>
                                    <a href="{{ route('staff.announcements.index') }}">View all</a>
                                </span>
                            </div>
                            <div id="qfBellList" class="qf-bell-list">
                                <p class="qf-bell-empty">No announcements yet.</p>
                            </div>
                        </div>
                    </div>
                    <span class="header-date">{{ now()->format('M d, Y · g:i A') }}</span>
                </div>
            </div>
        </header>
        <div class="admin-page staff-page p-6 max-w-4xl mx-auto">
            @if(session('status'))
                <div class="admin-alert qf-alert-success">{{ session('status') }}</div>
            @endif
            @if(session('success'))
                <div class="admin-alert qf-alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="admin-alert qf-alert-error">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="admin-alert qf-alert-error"><div style="font-weight:700;margin-bottom:4px;">Please correct the following:</div><ul style="list-style:disc;padding-left:18px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </div>
    </main>
</div>

<!-- Mobile drawer backdrop -->
<div class="qf-drawer-backdrop" id="qfDrawerBackdrop"></div>

<!-- =========================================================
     LOGOUT MODAL
========================================================= -->

<div id="logoutModal" class="qf-modal-overlay">

    <div id="logoutModalContent" class="qf-modal-card">

        <div class="qf-modal-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H7" />
            </svg>
        </div>

        <h2 class="qf-modal-title">Confirm Logout</h2>

        <p class="qf-modal-text">Do you want to logout from QueueFlow TMC?</p>

        <div class="qf-modal-actions">
            <button type="button" id="cancelLogout" class="qf-modal-cancel">Cancel</button>
            <form method="POST" action="{{ route('staff.logout') }}">
                @csrf
                <button type="submit" class="qf-modal-confirm">Logout</button>
            </form>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var logoutButton = document.getElementById('logoutButton');
    var logoutModal = document.getElementById('logoutModal');
    var cancelLogout = document.getElementById('cancelLogout');

    function closeLogoutModal() {
        if (!logoutModal) { return; }
        logoutModal.classList.add('hidden');
        logoutModal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    if (logoutButton && logoutModal) {
        logoutButton.addEventListener('click', function () {
            logoutModal.classList.remove('hidden');
            logoutModal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        });
    }

    if (cancelLogout) {
        cancelLogout.addEventListener('click', closeLogoutModal);
    }

    if (logoutModal) {
        logoutModal.addEventListener('click', function (event) {
            if (event.target === logoutModal) {
                closeLogoutModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && logoutModal && !logoutModal.classList.contains('hidden')) {
            closeLogoutModal();
        }
    });

    /* Mobile drawer */
    (function () {
        var sidebar = document.getElementById('qfStaffSidebar');
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
    })();

    /* Announcement bell: lightweight polling (no WebSocket broadcaster configured). */
    (function () {
        var bellButton = document.getElementById('qfBellButton');
        var bellBadge = document.getElementById('qfBellBadge');
        var bellPanel = document.getElementById('qfBellPanel');
        var bellList = document.getElementById('qfBellList');
        if (!bellButton || !bellBadge || !bellPanel || !bellList) { return; }

        var feedUrl = '{{ route('staff.announcements.recent') }}';
        var readAllUrl = '{{ route('staff.announcements.read-all') }}';
        var detailBase = '{{ url('staff/announcements') }}';
        var bellReadAll = document.getElementById('qfBellReadAll');
        var lastSeenId = 0;
        var firstLoad = true;

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function render(items) {
            if (!items.length) {
                bellList.innerHTML = '<p class="qf-bell-empty">No announcements yet.</p>';
                return;
            }
            bellList.innerHTML = items.map(function (n) {
                var when = n.created_at || '';
                var detail = '';
                try { detail = when ? new Date(when).toLocaleString() : ''; } catch (e) { detail = when; }
                var meta = [n.office || '', n.audience_label || '', detail].filter(function (p) { return p; }).join(' · ');
                return '<a class="qf-bell-item' + (n.is_read ? '' : ' qf-unread') + '" href="' + detailBase + '/' + n.id + '">' +
                    '<div class="qf-bell-title">' + escapeHtml(n.title || 'Announcement') + '</div>' +
                    '<div class="qf-bell-text">' + escapeHtml(n.message || '') + '</div>' +
                    '<div class="qf-bell-meta">' + escapeHtml(meta) + (n.is_read ? '' : ' · Unread') + '</div>' +
                    '</a>';
            }).join('');
        }

        function refresh() {
            fetch(feedUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) { return; }
                    var items = data.notifications || [];
                    var unread = data.unread_count || 0;
                    bellBadge.hidden = unread <= 0;
                    bellBadge.textContent = unread > 99 ? '99+' : unread;
                    if (bellReadAll) { bellReadAll.hidden = unread <= 0; }
                    render(items);
                    var maxId = items.reduce(function (m, n) { return Math.max(m, n.id || 0); }, 0);
                    if (!firstLoad && maxId > lastSeenId && unread > 0 && document.hidden !== true) {
                        // New announcement arrived while the console is open: nudge the bell
                        // plus a subtle gold halo (visual only; counts and panel logic untouched).
                        var calmMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        if (!calmMotion) {
                            bellButton.animate(
                                [{ transform: 'rotate(0)' }, { transform: 'rotate(-12deg)' }, { transform: 'rotate(10deg)' }, { transform: 'rotate(0)' }],
                                { duration: 450 }
                            );
                            bellButton.classList.remove('qf-bell-pulse');
                            void bellButton.offsetWidth;
                            bellButton.classList.add('qf-bell-pulse');
                            clearTimeout(bellButton._pulseT);
                            bellButton._pulseT = setTimeout(function () { bellButton.classList.remove('qf-bell-pulse'); }, 1800);
                        }
                    }
                    if (maxId > 0) { lastSeenId = Math.max(lastSeenId, maxId); }
                    firstLoad = false;
                })
                .catch(function () { /* keep the last rendered panel on network blips */ });
        }

        bellButton.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = bellPanel.hidden;
            bellPanel.hidden = !open;
            bellButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { refresh(); }
        });

        if (bellReadAll) {
            bellReadAll.addEventListener('click', function (e) {
                e.stopPropagation();
                var token = document.querySelector('meta[name="csrf-token"]');
                fetch(readAllUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
                    },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function () { refresh(); })
                .catch(function () { /* panel keeps its last state on failure */ });
            });
        }

        document.addEventListener('click', function (e) {
            if (!bellPanel.hidden && !bellPanel.contains(e.target) && e.target !== bellButton && !bellButton.contains(e.target)) {
                bellPanel.hidden = true;
                bellButton.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !bellPanel.hidden) {
                bellPanel.hidden = true;
                bellButton.setAttribute('aria-expanded', 'false');
            }
        });

        refresh();
        setInterval(refresh, 15000);
    })();

    /* Sidebar badges: refresh office-scoped counts without a page reload. */
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

        function refreshCounts() {
            if (document.hidden) { return; }
            fetch('{{ route('staff.sidebar-counts') }}', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(paint)
                .catch(function () { /* keep last painted badges on failure */ });
        }

        setInterval(refreshCounts, 30000);
    })();
});
</script>

@stack('scripts')
</body>
</html>
