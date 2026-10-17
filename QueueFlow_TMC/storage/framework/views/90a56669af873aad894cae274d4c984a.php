<?php $__env->startSection('title', 'Dashboard'); ?>

<?php $__env->startSection('content'); ?>

<div class="qf-dashboard w-full max-w-[1080px] mx-auto px-3 sm:px-4 min-w-0">

    

    

    <div class="mb-5">

        <h3 class="text-sm font-bold text-slate-900">
            Office Queue Statistics Today
        </h3>

        <p class="text-xs text-slate-500 mt-1 mb-3">
            Live queue figures for the management offices.<span id="qfSummaryUpdated"></span>
        </p>

        <div class="grid grid-cols-2 md:grid-cols-2 xl:grid-cols-4 gap-3" id="officeStatsGrid">

            <?php $__empty_1 = true; $__currentLoopData = $officeStats; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $office): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                <a
                    href="<?php echo e(route('queues.display', $office)); ?>"
                    data-office-id="<?php echo e($office->id); ?>"
                    data-office-stats="<?php echo e($office->code); ?>"
                    class="card qf-dashboard-card qf-reveal qf-tilt p-3 min-w-0 block hover:border-blue-300 hover:shadow-md transition"
                >

                    <div class="flex items-center justify-between gap-2">

                        <div class="min-w-0">

                            <div class="text-xs font-semibold text-slate-900 truncate" data-role="office-name">
                                <?php echo e($office->name); ?>

                            </div>

                            <div class="text-[10px] font-bold text-blue-600 mt-0.5" data-role="office-code">
                                <?php echo e($office->code); ?>

                            </div>

                        </div>

                        <?php if($office->activeDeployment): ?>
                            <span class="badge badge-<?php echo e($office->activeDeployment->status); ?>" data-role="office-batch">
                                Batch #<?php echo e($office->activeDeployment->batch_no); ?>

                            </span>
                        <?php endif; ?>

                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-3 text-center">

                        <div>
                            <div class="text-[9px] uppercase tracking-wide text-slate-400 font-bold">Today</div>
                            <div class="qf-count font-bold text-slate-900" data-target="<?php echo e($office->tickets_today); ?>" data-stat="today">0</div>
                        </div>

                        <div>
                            <div class="text-[9px] uppercase tracking-wide text-amber-500 font-bold">Waiting</div>
                            <div class="qf-count font-bold text-slate-900" data-target="<?php echo e($office->waiting_count); ?>" data-stat="waiting">0</div>
                        </div>

                        <div>
                            <div class="text-[9px] uppercase tracking-wide text-violet-500 font-bold">Serving</div>
                            <div class="qf-count font-bold text-slate-900" data-target="<?php echo e($office->serving_count); ?>" data-stat="serving">0</div>
                        </div>

                    </div>

                    <div class="flex items-center justify-between mt-3 pt-2 border-t border-slate-100">

                        <span class="text-[9px] uppercase tracking-wide text-emerald-500 font-bold">Served</span>

                        <span class="qf-count text-sm font-bold text-slate-900" data-target="<?php echo e($office->served_count); ?>" data-stat="served">0</span>

                        <span class="text-[10px] font-semibold text-blue-600">Open board →</span>

                    </div>

                </a>

            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

                <div class="card p-4 text-xs text-slate-500 col-span-full js-office-empty">
                    No offices registered yet. Add one in Offices to start tracking its queue here.
                </div>

            <?php endif; ?>

        </div>

    </div>


    

    <div class="card qf-dashboard-card qf-reveal qf-tilt min-w-0 overflow-hidden mb-5">

        <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">

            <div class="min-w-0">

                <h3 class="text-sm font-bold text-slate-900">
                    Campus Office Status
                </h3>

                <p class="text-[11px] text-slate-500 mt-1">
                    Live status per counter — each Registrar counter carries its own status from the staff assigned to it. An office is online while its assigned staff are signed in, and goes offline when they sign out. Offline offices stop accepting new queue tickets in the app, API and web portal.
                </p>

            </div>

        </div>

        <div class="p-3">

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3" id="officeStatusGrid">

                <?php $__empty_1 = true; $__currentLoopData = $officeStatus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $office): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                    <div
                        class="rounded-xl border p-3 flex items-center justify-between gap-3 min-w-0 <?php echo e($office['status'] === \App\Models\Office::STATUS_ONLINE ? 'border-emerald-200 bg-emerald-50/50' : 'border-red-200 bg-red-50/50'); ?>"
                        data-office-id="<?php echo e($office['office_id']); ?>"
                        data-office-group="<?php echo e($office['key']); ?>"
                    >

                        <div class="min-w-0">

                            <div class="text-sm font-bold text-slate-900" data-role="office-name">
                                <?php echo e($office['name']); ?>

                            </div>

                            <div class="text-[10px] text-slate-400" data-role="office-code">
                                <?php echo e($office['code']); ?>

                            </div>

                            <div class="flex items-center gap-2 mt-1">

                                <span class="badge js-office-status <?php echo e($office['status'] === \App\Models\Office::STATUS_ONLINE ? 'badge-online' : 'badge-offline'); ?>">
                                    <?php echo e(strtoupper($office['status'])); ?>

                                </span>

                            </div>

                        </div>

                    </div>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

                    <div class="card p-4 text-xs text-slate-500 col-span-full">
                        No operational offices found.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">

        
        <a
            href="<?php echo e(route('admin.staff.create')); ?>"
            class="card qf-dashboard-card qf-reveal qf-tilt p-3 min-w-0 hover:border-blue-300 hover:shadow-md transition"
        >

            <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-600">
                Quick Action
            </div>

            <div class="text-sm font-semibold text-slate-900 mt-1">
                Add Staff
            </div>

            <p class="text-xs text-slate-500 mt-1">
                Create and assign a staff account.
            </p>

        </a>


        
        <a
            href="<?php echo e(route('admin.reports.index')); ?>"
            class="card qf-dashboard-card qf-reveal qf-tilt p-3 min-w-0 hover:border-blue-300 hover:shadow-md transition"
        >

            <div class="text-[10px] font-bold uppercase tracking-wide text-violet-600">
                Quick Action
            </div>

            <div class="text-sm font-semibold text-slate-900 mt-1">
                Open Reports
            </div>

            <p class="text-xs text-slate-500 mt-1">
                Review queue volume and service performance.
            </p>

        </a>

    </div>


    

    <?php
        $hourlyCounts = $hourly->pluck('count');
        $hMax = max(1, $hourlyCounts->max());
        $pointCount = $hourly->count();
        $points = $hourly->values()->map(function ($h, $i) use ($hMax, $pointCount) {
            $x = $pointCount > 1 ? round($i * (420 / ($pointCount - 1)) + 10, 1) : 220;
            $y = round(120 - ($h['count'] / $hMax) * 100, 1);
            return [$x, $y];
        });
        $linePath = $points->map(fn ($p) => implode(',', $p))->implode(' L ');
        $linePath = 'M ' . $linePath;
        $areaPath = $linePath . ' L ' . $points->last()[0] . ',130 L ' . $points->first()[0] . ',130 Z';

        $breakdown = [
            ['label' => 'Waiting', 'value' => $stats['waiting'], 'color' => '#F59E0B'],
            ['label' => 'Serving', 'value' => $stats['serving'], 'color' => '#8B5CF6'],
            ['label' => 'Served', 'value' => $stats['served'], 'color' => '#10B981'],
            ['label' => 'No-Show', 'value' => $stats['no_show'] ?? 0, 'color' => '#EF4444'],
            ['label' => 'Total', 'value' => $stats['total_today'], 'color' => '#3B82F6'],
        ];
        $bMax = max(1, collect($breakdown)->max('value'));
    ?>

    <div class="qf-reveal-grid grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5 min-w-0">

        
        <div class="card qf-dashboard-card qf-reveal qf-tilt lg:col-span-2 min-w-0 p-4">
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-sm font-semibold text-slate-900">Queue Volume</h3>
                <p class="text-[11px] text-slate-500">Last 8 hours</p>
            </div>
            <svg viewBox="0 0 440 140" class="w-full h-36 mt-2" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="qfLineGrad" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#2563EB"/>
                        <stop offset="55%" stop-color="#3B82F6"/>
                        <stop offset="100%" stop-color="#8B5CF6"/>
                    </linearGradient>
                    <linearGradient id="qfAreaGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#3B82F6" stop-opacity="0.22"/>
                        <stop offset="100%" stop-color="#3B82F6" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <line x1="0" y1="35" x2="440" y2="35" stroke="rgba(100,116,139,.14)"/>
                <line x1="0" y1="70" x2="440" y2="70" stroke="rgba(100,116,139,.14)"/>
                <line x1="0" y1="105" x2="440" y2="105" stroke="rgba(100,116,139,.14)"/>
                <path pathLength="1" class="qf-line-fill" d="<?php echo e($areaPath); ?>" fill="url(#qfAreaGrad)"/>
                <path pathLength="1" class="qf-line-path" d="<?php echo e($linePath); ?>" fill="none" stroke="url(#qfLineGrad)" stroke-width="2.6" stroke-linecap="round"/>
            </svg>
            <div class="flex justify-between mt-1">
                <?php $__currentLoopData = $hourly; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $h): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <span class="text-[9px] text-slate-400"><?php echo e($h['label']); ?></span>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

        
        <div class="card qf-dashboard-card qf-reveal qf-tilt min-w-0 p-4">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Status Breakdown</h3>
            <div class="flex items-end gap-3 h-36">
                <?php $__currentLoopData = $breakdown; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="flex-1 flex flex-col items-center justify-end h-full">
                        <div class="qf-bar w-full rounded-t-md" style="height: <?php echo e(max(6, round($b['value'] / $bMax * 100))); ?>%; background: <?php echo e($b['color']); ?>; --bar-delay: <?php echo e($i * 90); ?>ms;"></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
            <div class="flex gap-3 mt-2">
                <?php $__currentLoopData = $breakdown; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <span class="flex-1 text-center text-[9px] text-slate-400 truncate"><?php echo e($b['label']); ?></span>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

    </div>


    

    <div class="qf-reveal-grid grid grid-cols-1 lg:grid-cols-3 gap-4 min-w-0">


        

        <style>
            .qf-acc-head { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; background: transparent; border: 0; cursor: pointer; text-align: left; }
            .qf-acc-head:hover { background: #f8fafc; }
            .qf-acc-head:focus-visible { outline: 2px solid #2563eb; outline-offset: -2px; }
            .qf-acc-chevron { flex-shrink: 0; color: #94a3b8; transition: transform .25s ease; }
            .qf-acc.open > .qf-acc-head > .qf-acc-chevron { transform: rotate(180deg); }
            .qf-acc-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .28s ease; }
            .qf-acc.open > .qf-acc-body { grid-template-rows: 1fr; }
            .qf-acc-inner { overflow: hidden; min-height: 0; }
            .qf-act-row { padding: 10px 4px; border-bottom: 1px solid #f1f5f9; }
            .qf-act-row:last-child { border-bottom: 0; }
            @media (prefers-reduced-motion: reduce) {
                .qf-acc-body, .qf-acc-chevron { transition: none; }
            }
            html.dark .qf-acc { border-color: #334155 !important; }
            html.dark .qf-acc-head:hover { background: #16203a !important; }
            html.dark .qf-acc-nested { background: rgba(15, 23, 42, .5) !important; border-color: #334155 !important; }
            html.dark .qf-act-row { border-color: #334155 !important; }
        </style>

        <div class="card qf-dashboard-card qf-reveal qf-tilt lg:col-span-2 min-w-0 overflow-hidden">

            
            <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">

                <div class="min-w-0">

                    <h3 class="text-sm font-semibold text-slate-900">
                        Recent Queue Activity
                    </h3>

                    <p class="text-[11px] text-slate-500 mt-1">
                        Latest generated and processed tickets, per office. Refreshes automatically.
                    </p>

                </div>

                <a
                    href="<?php echo e(route('admin.activity.index')); ?>"
                    class="flex-shrink-0 text-xs font-semibold text-blue-600 hover:text-blue-800"
                >
                    View all
                </a>

            </div>

            <div id="activityRefreshError" class="hidden mx-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] text-red-700">
                Couldn't refresh activity. Showing the last loaded records.
            </div>


            
            <div class="p-3 space-y-2" id="activityGroups">

                <?php $__empty_1 = true; $__currentLoopData = $activityGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                    <div class="qf-acc rounded-xl border border-slate-200" data-group="<?php echo e($group['key']); ?>">

                        <button
                            type="button"
                            class="qf-acc-head js-acc-toggle"
                            aria-expanded="false"
                        >
                            <span class="min-w-0 text-left">
                                <span class="block text-xs font-bold text-slate-900 truncate">
                                    <?php echo e($group['name']); ?>

                                </span>
                                <span class="block text-[10px] text-slate-400 mt-0.5">
                                    <span data-group-count="<?php echo e($group['key']); ?>"><?php echo e($group['tickets']->count()); ?></span> recent ticket(s)
                                </span>
                            </span>
                            <svg class="qf-acc-chevron" viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>

                        <div class="qf-acc-body">
                            <div class="qf-acc-inner">

                                <div class="px-3 pb-1" data-ticket-list="<?php echo e($group['key']); ?>">
                                    <?php $__empty_2 = true; $__currentLoopData = $group['tickets']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_2 = false; ?>
                                        <?php echo $__env->make('partials.activity-ticket-row', ['ticket' => $ticket], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_2): ?>
                                        <p class="text-[11px] text-slate-400 py-3 text-center">No recent activity for <?php echo e($group['name']); ?> yet.</p>
                                    <?php endif; ?>
                                </div>

                                <?php if(!empty($group['courses'])): ?>
                                    <div class="px-3 pb-3 space-y-2">
                                        <?php $__currentLoopData = $group['courses']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $course): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <div class="qf-acc qf-acc-nested rounded-lg border border-slate-100 bg-slate-50/60" data-course="<?php echo e($course['id']); ?>" data-course-code="<?php echo e($course['code']); ?>">
                                                <button
                                                    type="button"
                                                    class="qf-acc-head js-acc-toggle"
                                                    aria-expanded="false"
                                                >
                                                    <span class="min-w-0 text-left">
                                                        <span class="block text-[11px] font-bold text-slate-800 truncate">
                                                            <?php echo e($course['code']); ?> — <?php echo e($course['name']); ?>

                                                        </span>
                                                        <span class="block text-[10px] text-slate-400 mt-0.5">
                                                            <span data-course-count="<?php echo e($course['id']); ?>"><?php echo e($course['tickets']->count()); ?></span> recent ticket(s)
                                                        </span>
                                                    </span>
                                                    <svg class="qf-acc-chevron" viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                                </button>
                                                <div class="qf-acc-body">
                                                    <div class="qf-acc-inner">
                                                        <div class="px-3 pb-1" data-ticket-list="course-<?php echo e($course['id']); ?>">
                                                            <?php $__empty_2 = true; $__currentLoopData = $course['tickets']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_2 = false; ?>
                                                                <?php echo $__env->make('partials.activity-ticket-row', ['ticket' => $ticket], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
                                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_2): ?>
                                                                <p class="text-[11px] text-slate-400 py-3 text-center">No <?php echo e($course['code']); ?> tickets yet.</p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>

                    </div>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

                    <p class="text-xs text-slate-500 py-6 text-center">No queue activity yet.</p>

                <?php endif; ?>

            </div>

            <?php echo $__env->make('partials.pwd-proof-modal', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>

        </div>


        

        <div class="card min-w-0 overflow-hidden">

            
            <div class="p-3 border-b border-slate-100">

                <h3 class="text-sm font-semibold text-slate-900">
                    System Snapshot
                </h3>

                <p class="text-[11px] text-slate-500 mt-1">
                    Current system information.
                </p>

            </div>


            
            <div class="p-3 space-y-3">


                
                <div class="flex items-center justify-between gap-3">

                    <span class="text-xs text-slate-500">
                        Active Staff
                    </span>

                    <strong class="text-sm text-slate-900">
                        <?php echo e($stats['staff']); ?>

                    </strong>

                </div>


                
                <div class="flex items-center justify-between gap-3">

                    <span class="text-xs text-slate-500">
                        Active Students
                    </span>

                    <strong class="text-sm text-slate-900">
                        <?php echo e($stats['students']); ?>

                    </strong>

                </div>


                
                <div class="flex items-center justify-between gap-3">

                    <span class="text-xs text-slate-500">
                        Active Offices
                    </span>

                    <strong class="text-sm text-slate-900">
                        <?php echo e($stats['offices']); ?>

                    </strong>

                </div>


                
                <div class="pt-3 border-t border-slate-100">

                    <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold mb-2">
                        Latest Announcements
                    </div>


                    <?php $__empty_1 = true; $__currentLoopData = $announcements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $announcement): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                        <div class="mb-3 min-w-0">

                            <div class="text-xs font-semibold text-slate-900 truncate">
                                <?php echo e($announcement->title); ?>

                            </div>

                            <div class="text-[10px] text-slate-500 mt-1">
                                <?php echo e($announcement->created_at->format('M d, Y')); ?>

                            </div>

                        </div>

                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

                        <div class="text-xs text-slate-500">
                            No active announcements.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Activate the KPI fill bars (they start at width:0 via .qf-bar-fill).
    requestAnimationFrame(function () {
        document.querySelectorAll('.qf-bar-fill').forEach(function (el) {
            el.classList.add('qf-fill-on');
        });
    });

    // Count up each KPI number from 0 to its data-target value.
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('.qf-count').forEach(function (el) {
        var target = parseInt(el.dataset.target, 10) || 0;
        if (reduceMotion) { el.textContent = target; return; }
        var duration = 900;
        var start = performance.now();
        function step(t) {
            var p = Math.min(1, (t - start) / duration);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(eased * target);
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });

    /* Activity accordion: independent expand/collapse per office and course. */
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest ? event.target.closest('.js-acc-toggle') : null;
        if (!toggle) { return; }
        var acc = toggle.closest('.qf-acc');
        if (!acc) { return; }
        var open = acc.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* Activity refresh: same grouped rows as JSON, re-rendered in place so
       open groups stay open. Failures surface a banner, never fake rows. */
    (function () {
        var wrap = document.getElementById('activityGroups');
        if (!wrap) { return; }
        var feedUrl = '<?php echo e(route('admin.recent-activity')); ?>';
        var errBox = document.getElementById('activityRefreshError');

        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // The PWD indicator is the trigger: clicking it opens the proof modal
        // (real image when uploaded, otherwise a clear notice). Mirrors the
        // server-rendered partial so live refreshes look identical.
        function pwdBadgeHtml(t) {
            if (t.priority !== 'pwd') { return ''; }
            var hasProof = !!(t.has_evidence && t.evidence_url);
            return '<button type="button" class="js-pwd-proof badge badge-pwd qf-pwd-badge"' +
                ' title="' + (hasProof ? 'View PWD verification proof' : 'No PWD proof picture available for this student.') + '"' +
                ' aria-label="View PWD verification proof for queue ' + esc(t.queue_number) + '"' +
                ' data-url="' + (hasProof ? esc(t.evidence_url) : '') + '"' +
                ' data-student="' + esc(t.student) + '"' +
                ' data-student-id="' + esc(t.student_id) + '"' +
                ' data-office="' + esc(t.office) + '"' +
                ' data-queue="' + esc(t.queue_number) + '"' +
                ' data-purpose="' + esc(t.purpose) + '"' +
                ' data-status="' + esc(t.status) + '"' +
                ' data-time="' + esc(t.time) + '"' +
                '>PWD</button>';
        }

        function rowHtml(t) {
            var studentLine = esc(t.student);
            if (t.student_id) { studentLine += ' <span class="font-normal text-slate-400">(' + esc(t.student_id) + ')</span>'; }
            var sub = esc(t.office) + (t.course ? ' · ' + esc(t.course) : '') + ' · ' + esc(t.purpose || 'No purpose stated');
            var badgeCls = t.status === 'no_show' ? 'badge-cancelled' : 'badge-' + esc(t.status);
            var badgeTxt = t.status === 'no_show' ? 'No-Show' : esc(t.status);
            return '<div class="qf-act-row"><div class="flex items-start justify-between gap-3">' +
                '<div class="min-w-0">' +
                '<div class="flex items-center gap-2 flex-wrap">' +
                '<span class="text-xs font-bold text-slate-900">' + esc(t.queue_number) + '</span>' +
                '<span class="badge ' + badgeCls + '">' + badgeTxt + '</span>' +
                pwdBadgeHtml(t) +
                '</div>' +
                '<div class="text-xs font-semibold text-slate-700 mt-1 truncate">' + studentLine + '</div>' +
                '<div class="text-[11px] text-slate-500 mt-0.5">' + sub + '</div>' +
                '<div class="text-[10px] text-slate-400 mt-0.5">' + esc(t.time || '—') + '</div>' +
                '</div>' +
                '</div></div>';
        }

        function listHtml(tickets, emptyMsg) {
            if (!tickets || !tickets.length) {
                return '<p class="text-[11px] text-slate-400 py-3 text-center">' + esc(emptyMsg) + '</p>';
            }
            return tickets.map(rowHtml).join('');
        }

        function refresh() {
            if (document.hidden) { return; }
            fetch(feedUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !Array.isArray(data.groups)) {
                        if (errBox) { errBox.classList.remove('hidden'); }
                        return;
                    }
                    if (errBox) { errBox.classList.add('hidden'); }
                    data.groups.forEach(function (g) {
                        var list = wrap.querySelector('[data-ticket-list="' + g.key + '"]');
                        var count = wrap.querySelector('[data-group-count="' + g.key + '"]');
                        if (list) { list.innerHTML = listHtml(g.tickets, 'No recent activity for ' + g.name + ' yet.'); }
                        if (count) { count.textContent = (g.tickets || []).length; }
                        (g.courses || []).forEach(function (c) {
                            var cl = wrap.querySelector('[data-ticket-list="course-' + c.id + '"]');
                            var cc = wrap.querySelector('[data-course-count="' + c.id + '"]');
                            if (cl) { cl.innerHTML = listHtml(c.tickets, 'No ' + c.code + ' tickets yet.'); }
                            if (cc) { cc.textContent = (c.tickets || []).length; }
                        });
                    });
                })
                .catch(function () {
                    if (errBox) { errBox.classList.remove('hidden'); }
                });
        }

        setInterval(refresh, 30000);
    })();
});
</script>

<?php echo $__env->make('partials.live-refresh', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Live office numbers: stat cards + online/offline pills reconcile in
       place every 30s via the shared QfLive poller. New offices are
       inserted, renames update in place, deactivated/deleted offices are
       removed — never duplicated, never a full-page reload. Charts,
       filters and open accordions are untouched. */
    var stamp = document.getElementById('qfSummaryUpdated');
    var statsGrid = document.getElementById('officeStatsGrid');
    var statusGrid = document.getElementById('officeStatusGrid');

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setNum(card, key, value) {
        var el = card.querySelector('[data-stat="' + key + '"]');
        if (el && el.textContent !== String(value)) { el.textContent = value; }
    }

    function setText(root, role, value) {
        var el = root.querySelector('[data-role="' + role + '"]');
        if (el && el.textContent !== String(value)) { el.textContent = value; }
    }

    function findStatCard(o) {
        if (!statsGrid) { return null; }
        var byId = o.id != null ? statsGrid.querySelector('[data-office-id="' + o.id + '"]') : null;
        if (byId) { return byId; }
        // Fallback for markup rendered before the id key existed.
        return statsGrid.querySelector('[data-office-stats="' + o.code + '"]');
    }

    function findStatusPill(o) {
        if (!statusGrid) { return null; }
        var byId = o.id != null ? statusGrid.querySelector('[data-office-id="' + o.id + '"]') : null;
        if (byId) { return byId; }
        return statusGrid.querySelector('[data-office-group="' + o.code + '"]');
    }

    function buildStatCard(o) {
        var a = document.createElement('a');
        a.href = o.display_url || '#';
        a.setAttribute('data-office-id', o.id);
        a.setAttribute('data-office-stats', o.code);
        a.className = 'card qf-dashboard-card qf-reveal qf-tilt p-3 min-w-0 block hover:border-blue-300 hover:shadow-md transition';
        var batch = o.batch_no
            ? '<span class="badge badge-' + esc(o.batch_status || '') + '" data-role="office-batch">Batch #' + esc(o.batch_no) + '</span>'
            : '<span class="hidden" data-role="office-batch"></span>';
        a.innerHTML =
            '<div class="flex items-center justify-between gap-2">' +
                '<div class="min-w-0">' +
                    '<div class="text-xs font-semibold text-slate-900 truncate" data-role="office-name">' + esc(o.name) + '</div>' +
                    '<div class="text-[10px] font-bold text-blue-600 mt-0.5" data-role="office-code">' + esc(o.code) + '</div>' +
                '</div>' + batch +
            '</div>' +
            '<div class="grid grid-cols-3 gap-2 mt-3 text-center">' +
                '<div><div class="text-[9px] uppercase tracking-wide text-slate-400 font-bold">Today</div>' +
                    '<div class="qf-count font-bold text-slate-900" data-stat="today">' + esc(o.tickets_today) + '</div></div>' +
                '<div><div class="text-[9px] uppercase tracking-wide text-amber-500 font-bold">Waiting</div>' +
                    '<div class="qf-count font-bold text-slate-900" data-stat="waiting">' + esc(o.waiting) + '</div></div>' +
                '<div><div class="text-[9px] uppercase tracking-wide text-violet-500 font-bold">Serving</div>' +
                    '<div class="qf-count font-bold text-slate-900" data-stat="serving">' + esc(o.serving) + '</div></div>' +
            '</div>' +
            '<div class="flex items-center justify-between mt-3 pt-2 border-t border-slate-100">' +
                '<span class="text-[9px] uppercase tracking-wide text-emerald-500 font-bold">Served</span>' +
                '<span class="qf-count text-sm font-bold text-slate-900" data-stat="served">' + esc(o.served) + '</span>' +
                '<span class="text-[10px] font-semibold text-blue-600">Open board →</span>' +
            '</div>';
        return a;
    }

    function buildStatusPill(o) {
        var online = o.status === 'online';
        var div = document.createElement('div');
        div.setAttribute('data-office-id', o.id);
        div.setAttribute('data-office-group', o.code);
        div.className = 'rounded-xl border p-3 flex items-center justify-between gap-3 min-w-0 ' +
            (online ? 'border-emerald-200 bg-emerald-50/50' : 'border-red-200 bg-red-50/50');
        div.innerHTML =
            '<div class="min-w-0">' +
                '<div class="text-sm font-bold text-slate-900" data-role="office-name">' + esc(o.name) + '</div>' +
                '<div class="text-[10px] text-slate-400" data-role="office-code">' + esc(o.code) + '</div>' +
                '<div class="flex items-center gap-2 mt-1">' +
                    '<span class="badge js-office-status ' + (online ? 'badge-online' : 'badge-offline') + '">' +
                        esc(String(o.status || 'offline').toUpperCase()) + '</span>' +
                '</div>' +
            '</div>';
        return div;
    }

    function paintStatusPill(pill, o) {
        setText(pill, 'office-name', o.name);
        setText(pill, 'office-code', o.code);
        pill.setAttribute('data-office-group', o.code);
        var online = o.status === 'online';
        pill.classList.toggle('border-emerald-200', online);
        pill.classList.toggle('bg-emerald-50/50', online);
        pill.classList.toggle('border-red-200', !online);
        pill.classList.toggle('bg-red-50/50', !online);
        var badge = pill.querySelector('.js-office-status');
        if (badge) {
            badge.className = 'badge js-office-status ' + (online ? 'badge-online' : 'badge-offline');
            var label = String(o.status || 'offline').toUpperCase();
            if (badge.textContent !== label) { badge.textContent = label; }
        }
    }

    function paintBatch(card, o) {
        var badge = card.querySelector('[data-role="office-batch"]');
        if (o.batch_no) {
            var label = 'Batch #' + o.batch_no;
            if (!badge) {
                var head = card.querySelector('.flex.items-center.justify-between');
                badge = document.createElement('span');
                badge.setAttribute('data-role', 'office-batch');
                if (head) { head.appendChild(badge); }
                else { card.appendChild(badge); }
            }
            badge.classList.remove('hidden');
            badge.className = 'badge badge-' + (o.batch_status || '');
            badge.setAttribute('data-role', 'office-batch');
            if (badge.textContent !== label) { badge.textContent = label; }
        } else if (badge) {
            badge.remove();
        }
    }

    function paint(data) {
        if (!data || !Array.isArray(data.offices)) { return; }
        var seen = {};
        data.offices.forEach(function (o) {
            seen[String(o.id)] = true;
            var card = findStatCard(o);
            if (card) {
                card.setAttribute('data-office-id', o.id);
                card.setAttribute('data-office-stats', o.code);
                if (o.display_url && card.getAttribute('href') !== o.display_url) { card.setAttribute('href', o.display_url); }
                setText(card, 'office-name', o.name);
                setText(card, 'office-code', o.code);
                setNum(card, 'today', o.tickets_today);
                setNum(card, 'waiting', o.waiting);
                setNum(card, 'serving', o.serving);
                setNum(card, 'served', o.served);
                paintBatch(card, o);
            } else if (statsGrid) {
                var empty = statsGrid.querySelector('.js-office-empty');
                if (empty) { empty.remove(); }
                statsGrid.appendChild(buildStatCard(o));
            }
            var pill = findStatusPill(o);
            if (pill) {
                pill.setAttribute('data-office-id', o.id);
                paintStatusPill(pill, o);
            } else if (statusGrid) {
                statusGrid.appendChild(buildStatusPill(o));
            }
        });
        // Drop offices that were deactivated or deleted — keyed by id so a
        // rename or code change never orphans or duplicates a row.
        if (statsGrid) {
            statsGrid.querySelectorAll('[data-office-id]').forEach(function (el) {
                if (!seen[String(el.getAttribute('data-office-id'))]) { el.remove(); }
            });
            if (!statsGrid.querySelector('[data-office-id]') && !statsGrid.querySelector('.js-office-empty')) {
                var empty = document.createElement('div');
                empty.className = 'card p-4 text-xs text-slate-500 col-span-full js-office-empty';
                empty.textContent = 'No offices registered yet. Add one in Offices to start tracking its queue here.';
                statsGrid.appendChild(empty);
            }
        }
        if (statusGrid) {
            statusGrid.querySelectorAll('[data-office-id]').forEach(function (el) {
                if (!seen[String(el.getAttribute('data-office-id'))]) { el.remove(); }
            });
        }
        if (stamp) {
            try { stamp.textContent = ' Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }) + '.'; }
            catch (e) { stamp.textContent = ' Updated just now.'; }
        }
    }

    if (window.QfLive) {
        window.QfLive.poll({
            url: '<?php echo e(route('admin.queue-summary')); ?>',
            interval: 30000,
            onData: paint,
            onHealthy: function (ok) {
                if (stamp && !ok) { stamp.textContent = ' Reconnecting…'; }
            }
        });
    }
});
</script>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/admin/dashboard.blade.php ENDPATH**/ ?>