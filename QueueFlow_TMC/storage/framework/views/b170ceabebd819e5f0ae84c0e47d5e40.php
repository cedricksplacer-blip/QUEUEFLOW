

<?php $__env->startSection('title', 'Queueing Console'); ?>

<?php $__env->startSection('content'); ?>

<div class="qf-dashboard w-full max-w-[1080px] mx-auto px-3 sm:px-4 min-w-0">

    

    <div class="qf-reveal mb-5">

        <h3 class="text-sm font-bold text-slate-900">
            Welcome back, <?php echo e(auth()->user()->name); ?>

        </h3>

        <p class="text-xs text-slate-500 mt-1">
            <?php echo e($office->name); ?> - Queueing Console
            <?php if($deployment): ?>
                - Batch #<?php echo e($deployment->batch_no); ?> -
                <span class="uppercase font-semibold"><?php echo e($deployment->status); ?></span>
                - <?php echo e($deployment->submittedCount()); ?> submitted - <?php echo e($deployment->servedCount()); ?> served
            <?php else: ?>
                - No active batch yet
            <?php endif; ?>
        </p>

        <p class="text-xs text-slate-400 mt-1">
            <?php echo e(now()->format('l, F j, Y - g:i A')); ?>

        </p>

        <p class="text-[11px] text-slate-400 mt-1"><span id="qfLiveUpdated">Live — waiting list refreshes automatically</span><span id="qfLiveOffline" class="hidden font-bold text-amber-600"> · Reconnecting…</span></p>

    </div>

    <div id="qfLiveBanner" class="hidden mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 flex items-center justify-between gap-3">
        <span id="qfLiveBannerText" class="text-xs font-semibold text-blue-800">Queue updated.</span>
        <button type="button" id="qfLiveRefreshBtn" class="btn-primary text-xs flex-shrink-0">Refresh now</button>
    </div>


    

    <?php
        $admStatus = $deployment ? $deployment->status : null;
        $admLimit = $office->slotLimit();
        $admUsed = $deployment ? $deployment->submittedCount() : 0;
        $admPct = $admLimit ? round($admUsed / $admLimit * 100) : 0;
    $admBadges = [
        'open' => ['badge-serving', 'OPEN - accepting tickets'],
        'full' => ['badge-waiting', 'FULL - slots reached'],
        'closed' => ['badge-cancelled', 'CLOSED - not accepting tickets'],
        'deployed' => ['badge-serving', 'DEPLOYED - processing'],
        'completed' => ['badge-served', 'COMPLETED'],
    ];
    [$admBadgeClass, $admBadgeText] = $admBadges[$admStatus] ?? ['bg-slate-100 text-slate-600', 'No batch yet'];
    $admBatchState = $admStatus === 'open' ? 'ACTIVE' : ($admStatus === 'closed' ? 'CLOSED' : strtoupper((string) ($admStatus ?? 'NONE')));
?>
    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5" id="qfBatchCard" data-deployment-key="<?php echo e($deployment ? $deployment->id . '|' . $deployment->status . '|' . $deployment->batch_no : 'none'); ?>">

        <div class="p-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Batch Manager</h3>
                <p class="text-[11px] text-slate-500 mt-1">Deploy and run this office's batch. Every change is visible to the administrator.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-900" id="qfBatchNo">Batch #<?php echo e($deployment->batch_no ?? '-'); ?></span>
                <span class="badge <?php echo e($admBadgeClass); ?>" id="qfBatchBadge"><?php echo e($admBadgeText); ?></span>
                <span class="text-[10px] font-bold text-slate-500">Batch Status: <span id="qfBatchState" class="<?php echo e($admBatchState === 'ACTIVE' ? 'text-emerald-600' : ($admBatchState === 'CLOSED' ? 'text-red-600' : 'text-slate-500')); ?>"><?php echo e($admBatchState); ?></span></span>
            </div>
        </div>

        <div class="p-4">
            <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                <span id="qfBatchSlots"><?php echo e($admUsed); ?> / <?php echo e($admLimit); ?> slots used</span>
                <span id="qfBatchServedTxt"><?php if($deployment): ?> <?php echo e($deployment->servedCount()); ?> served <?php endif; ?></span>
            </div>
            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-2 rounded-full <?php echo e($admPct >= 100 ? 'bg-amber-500' : 'bg-blue-500'); ?>" id="qfBatchBar" style="width:<?php echo e($admPct); ?>%"></div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-4">
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-2 text-center">
                    <div class="text-lg font-bold text-slate-900" id="qfBatchSubmitted"><?php echo e($deployment ? $deployment->submittedCount() : 0); ?></div>
                    <div class="text-[9px] uppercase tracking-wide text-slate-400 font-bold">Submitted</div>
                </div>
                <div class="rounded-xl bg-amber-50 border border-amber-100 p-2 text-center">
                    <div class="text-lg font-bold text-amber-600" id="qfBatchWaiting"><?php echo e($deployment->waiting_count ?? 0); ?></div>
                    <div class="text-[9px] uppercase tracking-wide text-amber-500 font-bold">In Line</div>
                </div>
                <div class="rounded-xl bg-violet-50 border border-violet-100 p-2 text-center">
                    <div class="text-lg font-bold text-violet-600" id="qfBatchServing"><?php echo e($deployment->serving_count ?? 0); ?></div>
                    <div class="text-[9px] uppercase tracking-wide text-violet-500 font-bold">Serving</div>
                </div>
                <div class="rounded-xl bg-emerald-50 border border-emerald-100 p-2 text-center">
                    <div class="text-lg font-bold text-emerald-600" id="qfBatchServed"><?php echo e($deployment ? $deployment->servedCount() : 0); ?></div>
                    <div class="text-[9px] uppercase tracking-wide text-emerald-500 font-bold">Served</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mt-4 items-end">
                <?php if(! $deployment): ?>
                    <form method="POST" action="<?php echo e(route('staff.admissions.deploy')); ?>" class="flex items-end gap-2 flex-wrap"><?php echo csrf_field(); ?>
                        <label class="text-[11px] font-semibold text-slate-500">Queue slots:<input type="number" name="max_slots" value="<?php echo e(old('max_slots', $office->slotLimit())); ?>" min="1" max="500" step="1" required class="ml-1 w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold text-slate-900 text-center"></label>
                        <button class="btn-primary text-xs">Deploy Batch <?php echo e($nextBatchNo ?? 1); ?></button>
                    </form>
                <?php endif; ?>
                <?php if($admStatus === 'open' || $admStatus === 'full'): ?>
                    <form method="POST" action="<?php echo e(route('staff.admissions.close-batch')); ?>" onsubmit="return confirm('Close Batch #<?php echo e($deployment->batch_no); ?>? No new queue tickets will be issued until a new batch is deployed. Tickets already issued stay in line.')"><?php echo csrf_field(); ?><button class="btn-secondary text-xs !text-red-700 !border-red-200">Close Batch</button></form>
                <?php endif; ?>
                <?php if($admStatus === 'deployed'): ?>
                    <form method="POST" action="<?php echo e(route('staff.admissions.complete')); ?>" onsubmit="return confirm('Mark the batch as completed and open a new 100-slot batch?')"><?php echo csrf_field(); ?><button class="btn-secondary text-xs">Complete & Start New Batch</button></form>
                <?php endif; ?>
                <?php if($admStatus === 'closed'): ?>
                    <form method="POST" action="<?php echo e(route('staff.admissions.start')); ?>" onsubmit="return confirm('Deploy a new batch for <?php echo e($office->name); ?>? It will take the next batch number.')" class="flex items-end gap-2 flex-wrap"><?php echo csrf_field(); ?>
                        <label class="text-[11px] font-semibold text-slate-500">Queue slots:<input type="number" name="max_slots" value="<?php echo e(old('max_slots', $office->slotLimit())); ?>" min="1" max="500" step="1" required class="ml-1 w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold text-slate-900 text-center"></label>
                        <button class="btn-secondary text-xs">Deploy Batch <?php echo e($nextBatchNo ?? 1); ?></button>
                    </form>
                <?php endif; ?>
                <?php if($admStatus === 'completed'): ?>
                    <form method="POST" action="<?php echo e(route('staff.admissions.start')); ?>" onsubmit="return confirm('Open a new 100-slot batch for <?php echo e($office->name); ?>?')"><?php echo csrf_field(); ?><button class="btn-secondary text-xs">Deploy Batch <?php echo e($nextBatchNo ?? 1); ?></button></form>
                <?php endif; ?>
                <?php echo $__env->make('staff.partials.reset-batch-number', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
            </div>
            <?php $__errorArgs = ['max_slots'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="text-[11px] text-red-600 font-semibold mt-2"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>
</div>



    

    <div class="qf-reveal-grid grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5 min-w-0">

        
        <div class="card qf-dashboard-card qf-reveal lg:col-span-2 min-w-0 overflow-hidden">

            <div class="p-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-slate-900">
                        Students in Line
                        <span class="ml-1 text-xs font-bold text-amber-600" id="qfWaitingCount">(<?php echo e($waiting->count()); ?>)</span>
                    </h3>
                    <p class="text-[11px] text-slate-500 mt-1">Live waiting list for <?php echo e($office->name); ?>. PWD applicants go to the front — use ▶ Call Next beside a verified PWD ticket to call them directly.</p>
                </div>
                <form method="POST" action="<?php echo e(route('staff.queues.call-next')); ?>" class="flex items-center gap-2 flex-wrap" id="qfCallNextForm">
                    <?php echo csrf_field(); ?>
                    <label for="qfCallDuration" class="text-[11px] font-semibold text-slate-500">Call Duration:</label>
                    <div class="flex items-center gap-1">
                        <input type="number" id="qfCallDuration" name="call_duration_minutes" value="<?php echo e(old('call_duration_minutes', $callTimeout ?? \App\Models\QueueTicket::DEFAULT_CALL_DURATION_MINUTES)); ?>" min="<?php echo e(\App\Models\QueueTicket::MIN_CALL_DURATION_MINUTES); ?>" max="<?php echo e(\App\Models\QueueTicket::MAX_CALL_DURATION_MINUTES); ?>" step="1" required
                            class="w-16 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold text-slate-900 text-center" title="Minutes for this call (1-60)">
                        <span class="text-[11px] text-slate-400 font-semibold">min</span>
                    </div>
                    <select id="qfCallDurationPreset" class="rounded-lg border border-slate-200 px-1.5 py-1.5 text-[11px] text-slate-600" title="Quick durations">
                        <option value="1" <?php if(($callTimeout ?? 5) == 1): echo 'selected'; endif; ?>>1 min</option>
                        <option value="3" <?php if(($callTimeout ?? 5) == 3): echo 'selected'; endif; ?>>3 min</option>
                        <option value="5" <?php if(($callTimeout ?? 5) == 5): echo 'selected'; endif; ?>>5 min</option>
                        <option value="10" <?php if(($callTimeout ?? 5) == 10): echo 'selected'; endif; ?>>10 min</option>
                    </select>
                    <button type="submit" class="btn-primary text-xs" <?php if(!$waiting->count() || $nowServing): ?> disabled <?php endif; ?>>▶ Call Next</button>
                </form>
                <?php $__errorArgs = ['call_duration_minutes'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="w-full text-[11px] text-red-600 font-semibold"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                
                <form method="POST" action="<?php echo e(route('staff.queues.timeout')); ?>" class="w-full flex items-center gap-2 flex-wrap mt-2 rounded-lg bg-slate-50 border border-slate-100 px-2 py-1.5" title="Saved default for this office">
                    <?php echo csrf_field(); ?>
                    <span class="text-[11px] font-bold text-slate-500">Queue Timeout Duration (saved):</span>
                    <div class="flex items-center gap-1">
                        <input type="number" name="call_duration_minutes" value="<?php echo e($callTimeout ?? \App\Models\QueueTicket::DEFAULT_CALL_DURATION_MINUTES); ?>" min="<?php echo e(\App\Models\QueueTicket::MIN_CALL_DURATION_MINUTES); ?>" max="<?php echo e(\App\Models\QueueTicket::MAX_CALL_DURATION_MINUTES); ?>" step="1" required
                            class="w-16 rounded-lg border border-slate-200 px-2 py-1 text-xs font-bold text-slate-900 text-center">
                        <span class="text-[11px] text-slate-400 font-semibold">min (default 5)</span>
                    </div>
                    <button type="submit" class="btn-secondary text-xs">Save Timeout</button>
                    <span class="text-[10px] text-slate-400">Next calls use this unless overridden above.</span>
                </form>
            </div>

            <div class="p-3" id="qfWaitingList">
                <?php $__empty_1 = true; $__currentLoopData = $waiting; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="flex items-center justify-between gap-3 py-2.5 border-b border-slate-100 last:border-0" data-ticket-id="<?php echo e($ticket->id); ?>">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="flex-shrink-0 w-7 h-7 rounded-full <?php echo e($loop->first ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-500'); ?> text-xs font-bold flex items-center justify-center"><?php echo e($index + 1); ?></span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-bold text-blue-800"><?php echo e($ticket->queue_number); ?></span>
                                    <?php if($showNames): ?><span class="text-sm font-semibold text-slate-700 truncate"><?php echo e($ticket->student->name ?? 'Walk-in'); ?></span><?php endif; ?>
                                    <?php if($ticket->isPwd()): ?><span class="badge badge-pwd">PWD</span><?php endif; ?>
                                    <?php if($ticket->course): ?><span class="text-[10px] font-semibold text-slate-400 uppercase"><?php echo e($ticket->course->code); ?></span><?php endif; ?>
                                </div>
                                <?php if($ticket->purpose): ?><div class="text-[11px] text-slate-400 mt-0.5 truncate"><?php echo e($ticket->purpose); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <?php if($ticket->isPwd() && $ticket->priority_evidence): ?>
                                <a href="<?php echo e(route('staff.queues.evidence', $ticket)); ?>" target="_blank" class="text-xs text-blue-600 underline">evidence</a>
                            <?php endif; ?>
                            <?php if($ticket->isPwd() && $ticket->hasVerifiedEvidence()): ?>
                                <form method="POST" action="<?php echo e(route('staff.queues.call-next')); ?>" class="qf-pwd-call-form" onsubmit="this.querySelector('button').disabled = true;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="ticket_id" value="<?php echo e($ticket->id); ?>">
                                    <input type="hidden" name="call_duration_minutes" class="qf-pwd-duration" value="<?php echo e(old('call_duration_minutes', $callTimeout ?? \App\Models\QueueTicket::DEFAULT_CALL_DURATION_MINUTES)); ?>">
                                    <button type="submit" class="btn-primary text-xs" <?php if($nowServing): ?> disabled title="Finish the ticket being served first" <?php endif; ?>>▶ Call Next</button>
                                </form>
                            <?php endif; ?>
                            <?php if($loop->first && ! $nowServing): ?>
                                <span class="badge badge-waiting">next</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="py-6 text-center">
                        <?php if($deployment && $deployment->status === \App\Models\QueueDeployment::STATUS_DEPLOYED): ?>
                            <div class="text-2xl mb-2">🚶</div>
                            <p class="text-sm font-semibold text-slate-700">No students in line right now.</p>
                            <p class="text-xs text-slate-400 mt-1">This batch is deployed for serving, so intake is closed. Complete the batch above to open fresh slots for students.</p>
                        <?php else: ?>
                            <div class="text-2xl mb-2">🎛️</div>
                            <p class="text-sm font-semibold text-slate-700">The batch is not accepting tickets yet.</p>
                            <p class="text-xs text-slate-400 mt-1">Deploy the batch above to open 100 slots and start queueing students.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            </div>

        
        <div class="space-y-4 min-w-0">

            <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden">

                <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900">Now Serving</h3>
                        <p class="text-[11px] text-slate-500 mt-1">Current ticket at <?php echo e($office->code); ?>.</p>
                    </div>
                    <?php if($nowServing): ?><span class="badge badge-serving">serving</span><?php endif; ?>
                </div>

                <div class="p-4">
                    <?php if($nowServing): ?>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-4xl font-extrabold text-slate-900 tracking-tight"><?php echo e($nowServing->queue_number); ?></p>
                                <div class="text-xs text-slate-500 mt-2">
                                    <div class="text-sm font-semibold text-slate-700"><?php echo e($nowServing->student->name ?? 'Walk-in'); ?>

                                        <?php if($nowServing->course): ?><span class="text-slate-400 font-normal"> - <?php echo e($nowServing->course->code); ?></span><?php endif; ?>
                                        <?php if($nowServing->isPwd()): ?><span class="badge badge-pwd">PWD</span><?php endif; ?>
                                    </div>
                                    <div class="mt-1 text-[11px] text-slate-400"><?php echo e($office->name); ?> · <?php echo e($office->code); ?></div>
                                    <?php if($nowServing->purpose): ?><div class="mt-1"><?php echo e($nowServing->purpose); ?></div><?php endif; ?>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1.5">
                                <span class="badge badge-serving" id="qfStatusBadge">serving</span>
                                <?php if((int) ($nowServing->recall_count ?? 0) > 0): ?>
                                    <span class="text-[10px] font-bold text-slate-400">Called <?php echo e((int) $nowServing->recall_count + 1); ?>×</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if($nowServing->arrived_at): ?>
                        <div class="mt-3 rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3"
                            id="qfCountdownBox"
                            data-ticket-id="<?php echo e($nowServing->id); ?>"
                            data-queue-number="<?php echo e($nowServing->queue_number); ?>"
                            data-student="<?php echo e($nowServing->student->name ?? 'Walk-in'); ?>"
                            data-office="<?php echo e($office->name); ?>"
                            data-arrived="1"
                            data-arrived-at="<?php echo e(optional($nowServing->arrived_at)->toIso8601String()); ?>"
                            data-duration="<?php echo e($nowServing->effectiveCallDuration()); ?>">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[10px] uppercase tracking-widest font-bold text-emerald-600">Student arrived — timer stopped</div>
                                    <div class="mt-1 text-3xl font-extrabold text-emerald-800 tabular-nums" id="qfCountdown">ARRIVED</div>
                                    <div class="mt-1 text-[11px] font-semibold text-emerald-700" id="qfCountdownNote">Arrived <?php echo e(optional($nowServing->arrived_at)->format('g:i A') ?? '—'); ?> — being attended to. Continue to Mark Served when done.</div>
                                </div>
                                <span class="badge badge-served" id="qfCountdownBadge">arrived</span>
                            </div>
                            <div class="mt-3 h-2 rounded-full bg-emerald-100 overflow-hidden" aria-hidden="true">
                                <div id="qfProgressBar" class="h-2 rounded-full bg-emerald-500 transition-all duration-1000" style="width:100%"></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 transition-colors"
                            id="qfCountdownBox"
                            data-ticket-id="<?php echo e($nowServing->id); ?>"
                            data-queue-number="<?php echo e($nowServing->queue_number); ?>"
                            data-student="<?php echo e($nowServing->student->name ?? 'Walk-in'); ?>"
                            data-office="<?php echo e($office->name); ?>"
                            data-called-at="<?php echo e(optional($nowServing->called_at)->toIso8601String()); ?>"
                            data-expires-at="<?php echo e(optional($nowServing->expires_at)->toIso8601String()); ?>"
                            data-duration="<?php echo e($nowServing->effectiveCallDuration()); ?>"
                            data-recall-count="<?php echo e((int) ($nowServing->recall_count ?? 0)); ?>">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[10px] uppercase tracking-widest font-bold text-slate-400" id="qfCountdownLabel">Time remaining (<?php echo e($nowServing->effectiveCallDuration()); ?> min call)</div>
                                    <div class="mt-1 text-3xl sm:text-4xl font-extrabold text-slate-900 tabular-nums" id="qfCountdown">--:--</div>
                                    <div class="mt-1 text-[11px] font-semibold text-slate-500" id="qfCountdownNote">Called <?php echo e(optional($nowServing->called_at)->format('g:i A') ?? '—'); ?></div>
                                </div>
                                <div class="flex flex-col items-end gap-2">
                                    <span class="badge badge-serving" id="qfCountdownBadge">live</span>
                                    <span class="text-[10px] font-semibold text-slate-400 tabular-nums" id="qfCountdownMeta"></span>
                                </div>
                            </div>
                            <div class="mt-3 h-2.5 rounded-full bg-slate-200 overflow-hidden" role="progressbar" aria-label="Call time remaining" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100" id="qfProgressWrap">
                                <div id="qfProgressBar" class="h-2.5 rounded-full bg-blue-500 transition-all duration-1000" style="width:100%"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-[10px] font-semibold text-slate-400">
                                <span id="qfProgressHint">Server-synced · auto-updates every second</span>
                                <span id="qfServerTime" class="tabular-nums"></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="flex flex-wrap gap-2 mt-4">
                            <?php if($nowServing->isPwd() && $nowServing->priority_evidence): ?>
                                <a href="<?php echo e(route('staff.queues.evidence', $nowServing)); ?>" target="_blank" class="btn-primary inline-flex items-center gap-1">View Evidence</a>
                            <?php endif; ?>
                            <?php if(! $nowServing->arrived_at): ?>
                            <form method="POST" action="<?php echo e(route('staff.queues.arrive', $nowServing)); ?>" class="qf-guard-double" onsubmit="var b=this.querySelector('button'); if(b){b.disabled=true;}">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn-primary text-xs !bg-emerald-600" title="Confirm arrival and stop the countdown">Stop Timer ✓ Arrived</button>
                            </form>
                            <form method="POST" action="<?php echo e(route('staff.queues.recall', $nowServing)); ?>" class="qf-guard-double" onsubmit="var b=this.querySelector('button'); if(b){b.disabled=true;}">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="call_duration_minutes" value="<?php echo e($nowServing->effectiveCallDuration()); ?>">
                                <button type="submit" class="btn-secondary text-xs" title="Call the same student again with a fresh <?php echo e($nowServing->effectiveCallDuration()); ?>-minute deadline">↻ Call Again</button>
                            </form>
                            <?php endif; ?>
<form method="POST" action="<?php echo e(route('staff.queues.served', $nowServing)); ?>" <?php if($confirmServed): ?> onsubmit="return confirm('Mark <?php echo e($nowServing->queue_number); ?> as served?');" <?php endif; ?>>
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="btn-danger">Mark Served ✓</button>
                        </form>
                            <form method="POST" action="<?php echo e(route('staff.queues.no-show', $nowServing)); ?>" onsubmit="return confirm('Mark <?php echo e($nowServing->queue_number); ?> as No-Show? The ticket leaves the queue permanently and is kept in history.');">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn-secondary text-xs" <?php if($nowServing->arrived_at): ?> disabled title="Student already arrived" <?php endif; ?>>No-Show ↩</button>
                            </form>
                            <form method="POST" action="<?php echo e(route('staff.queues.cancel', $nowServing)); ?>" onsubmit="var r=this.querySelector('input[name=cancel_reason]'); if(!r.value.trim()){ alert('Please enter a cancellation reason before cancelling.'); r.focus(); return false; } return confirm('Cancel the call for <?php echo e($nowServing->queue_number); ?>? This voids the ticket and frees the window for the next student.');">
                                <?php echo csrf_field(); ?>
                                <input type="text" name="cancel_reason" maxlength="255" required placeholder="Reason (required)" value="<?php echo e(old('cancel_reason')); ?>" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs text-slate-700 w-40" title="Cancellation reason shown to the student">
                                <button type="submit" class="btn-secondary text-xs !text-red-700 !border-red-200">Cancel Call ✕</button>
                            </form>
                        </div>
                    <?php elseif($waiting->count()): ?>
                        <p class="text-sm text-slate-500">No ticket is being served right now.</p>
                        <form method="POST" action="<?php echo e(route('staff.queues.call-next')); ?>" class="mt-3 flex items-center gap-2 flex-wrap">
                            <?php echo csrf_field(); ?>
                            <label for="qfCallDurationEmpty" class="text-[11px] font-semibold text-slate-500">Call Duration:</label>
                            <div class="flex items-center gap-1">
                                <input type="number" id="qfCallDurationEmpty" name="call_duration_minutes" value="<?php echo e(old('call_duration_minutes', $callTimeout ?? \App\Models\QueueTicket::DEFAULT_CALL_DURATION_MINUTES)); ?>" min="<?php echo e(\App\Models\QueueTicket::MIN_CALL_DURATION_MINUTES); ?>" max="<?php echo e(\App\Models\QueueTicket::MAX_CALL_DURATION_MINUTES); ?>" step="1" required
                                    class="w-16 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold text-slate-900 text-center">
                                <span class="text-[11px] text-slate-400 font-semibold">min</span>
                            </div>
                            <button type="submit" class="btn-primary">▶ Call Next in Line</button>
                        </form>
                    <?php else: ?>
                        <p class="text-sm text-slate-500">No ticket is being served right now.</p>
                        <p class="text-xs text-slate-400 mt-1">Call the next student in line when ready.</p>
                    <?php endif; ?>
                </div>

            </div>

            <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden">

                <div class="p-3 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-900">Batch Status</h3>
                </div>

                <div class="p-3 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-500">Office</span>
                        <strong class="text-sm text-slate-900"><?php echo e($office->code); ?></strong>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-500">Batch</span>
                        <?php if($deployment): ?>
                            <span class="badge <?php echo e($deployment->status === \App\Models\QueueDeployment::STATUS_DEPLOYED ? 'badge-serving' : 'badge-waiting'); ?>"><?php echo e(strtoupper($deployment->status)); ?></span>
                        <?php else: ?>
                            <span class="text-slate-400">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-500">Acceptance</span>
                        <strong class="text-sm text-slate-900 text-right"><?php echo e($office->opening_time ? \Carbon\Carbon::parse($office->opening_time)->format('g:i A') : 'N/A'); ?> - <?php echo e($office->closing_time ? \Carbon\Carbon::parse($office->closing_time)->format('g:i A') : 'N/A'); ?></strong>
                    </div>
                    <?php if(! $deployment && $lastDeployment && $lastDeployment->status === \App\Models\QueueDeployment::STATUS_COMPLETED): ?>
                        <div class="pt-3 border-t border-slate-100 text-[11px] text-slate-400">The last batch was fully served. Start a new batch to accept more applicants.</div>
                    <?php endif; ?>
                </div>

                <div class="p-3 pt-0 flex flex-col gap-2">
                    <a href="<?php echo e(route('queues.display', $office)); ?>" target="_blank" class="btn-secondary justify-center">🖥️ Open Live Display</a>
                    <a href="<?php echo e(route('staff.settings.index')); ?>" class="btn-secondary justify-center">⚙️ Staff Settings</a>
                </div>

            </div>

        </div>

    </div>


    

    <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden mb-5">

        <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Cancellation Alerts <?php if(($cancellationUnread ?? 0) > 0): ?><span class="badge badge-cancelled"><?php echo e($cancellationUnread); ?> new</span><?php endif; ?></h3>
                <p class="text-[11px] text-slate-500 mt-1">Students who cancelled at <?php echo e($office->name); ?>. Updates automatically.</p>
            </div>
            <a href="<?php echo e(route('staff.notifications.index')); ?>" class="flex-shrink-0 text-xs font-semibold text-blue-600 hover:text-blue-800">View all →</a>
        </div>

        <div class="p-3" id="qfCancelAlerts">
            <?php $__empty_1 = true; $__currentLoopData = ($cancellationAlerts ?? collect()); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $alert): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="flex items-start justify-between gap-3 py-2.5 border-b border-slate-100 last:border-0">
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="font-bold text-red-700"><?php echo e($alert->queue_number); ?></span>
                            <span class="text-xs font-semibold text-slate-700"><?php echo e($alert->student_name); ?></span>
                            <?php if($alert->student_id_no): ?><span class="text-[10px] font-semibold text-slate-400"><?php echo e($alert->student_id_no); ?></span><?php endif; ?>
                            <?php if(!$alert->is_read): ?><span class="badge badge-cancelled">new</span><?php endif; ?>
                        </div>
                        <div class="text-[11px] text-slate-500 mt-0.5">Student Cancelled Ticket — Student ID: <?php echo e($alert->student_id_no ?? '—'); ?> cancelled ticket <?php echo e($alert->queue_number); ?> at the <?php echo e($alert->office_name); ?>.</div>
                        <div class="text-[10px] text-slate-400 mt-0.5"><?php echo e($alert->purpose ?? 'No purpose stated'); ?> · <?php echo e(ucfirst($alert->priority ?? 'regular')); ?> · <?php echo e(optional($alert->cancelled_at)->format('M d, Y g:i A') ?? ''); ?></div>
                    </div>
                    <a href="<?php echo e(route('staff.notifications.show', $alert)); ?>" class="flex-shrink-0 text-xs text-blue-600 underline">details</a>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-sm text-slate-500 py-4 text-center">No cancellations at <?php echo e($office->name); ?> yet.</p>
            <?php endif; ?>
        </div>

    </div>


    

    <div class="qf-reveal-grid grid grid-cols-1 lg:grid-cols-2 gap-4 min-w-0">

        <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden">

            <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Recently Served</h3>
                    <p class="text-[11px] text-slate-500 mt-1">Students completed today, newest first.</p>
                </div>
                <a href="<?php echo e(route('staff.history.index', ['date' => today()->toDateString()])); ?>" class="flex-shrink-0 text-xs font-semibold text-blue-600 hover:text-blue-800">View Full History →</a>
            </div>

            <div class="p-4">
                <?php if(($recentServed ?? collect())->count()): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php $__currentLoopData = $recentServed; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                                <span class="badge badge-served"><?php echo e($ticket->queue_number); ?></span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-slate-700 truncate"><?php echo e($ticket->student->name ?? 'Walk-in'); ?></div>
                                    <?php if($ticket->course): ?><div class="text-[10px] text-slate-400 uppercase"><?php echo e($ticket->course->code); ?></div><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">No students served yet today.</p>
                <?php endif; ?>
            </div>

        </div>

        <div class="card qf-dashboard-card qf-reveal min-w-0 overflow-hidden">

            <div class="p-3 border-b border-slate-100 flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">No-Show Records <span class="ml-1 text-xs font-bold text-red-600">(<?php echo e($noShowCount ?? 0); ?>)</span></h3>
                    <p class="text-[11px] text-slate-500 mt-1">Tickets marked No-Show today. Kept in history and reports.</p>
                </div>
                <a href="<?php echo e(route('staff.history.index', ['date' => today()->toDateString(), 'status' => 'no_show'])); ?>" class="flex-shrink-0 text-xs font-semibold text-blue-600 hover:text-blue-800">View All →</a>
            </div>

            <div class="p-4">
                <?php if(($recentNoShow ?? collect())->count()): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php $__currentLoopData = $recentNoShow; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="flex items-center gap-3 rounded-xl bg-red-50 border border-red-100 px-3 py-2">
                                <span class="badge badge-cancelled"><?php echo e($ticket->queue_number); ?></span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-slate-700 truncate"><?php echo e($ticket->student->name ?? 'Walk-in'); ?></div>
                                    <div class="text-[10px] text-slate-400"><?php echo e(optional($ticket->no_show_at)->format('g:i A') ?? ''); ?> · <?php echo e($ticket->noShowByStaff->name ?? 'staff'); ?></div>
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">No No-Show records today.</p>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>


<div id="qfToast" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-[70] hidden max-w-[92vw]">
    <div class="rounded-xl px-4 py-2.5 text-xs font-semibold shadow-lg border" id="qfToastInner"></div>
</div>


<div id="qfExpiryModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="qfModalTitle">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" data-qf-modal-dismiss></div>
    <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="px-5 pt-5 pb-4 border-b border-red-100 bg-red-50/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 id="qfModalTitle" class="text-base font-extrabold text-slate-900">Queue Call Time Expired</h3>
                    <p class="text-[11px] text-slate-500 mt-1">The student's call duration reached zero. Choose how to proceed.</p>
                </div>
                <span class="badge badge-cancelled">expired</span>
            </div>
        </div>
        <div class="px-5 py-4 space-y-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">Ticket Number</span>
                <strong class="text-slate-900" id="qfModalTicket">—</strong>
            </div>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">Student</span>
                <strong class="text-slate-900 truncate max-w-[60%]" id="qfModalStudent">—</strong>
            </div>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">Office</span>
                <strong class="text-slate-900" id="qfModalOffice">—</strong>
            </div>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">Status</span>
                <span class="badge badge-cancelled" id="qfModalStatus">Time Expired</span>
            </div>
            <p class="text-[11px] text-slate-400 pt-1" id="qfModalMeta">—</p>
            <p class="hidden text-[11px] font-semibold text-red-600" id="qfModalError"></p>
        </div>
        <div class="px-5 pb-5 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button type="button" id="qfModalRecall" class="btn-primary justify-center text-sm disabled:opacity-60">
                <span class="qf-btn-label">↻ Call Again</span>
            </button>
            <button type="button" id="qfModalNoShow" class="btn-secondary justify-center text-sm !text-red-700 !border-red-200 disabled:opacity-60">
                <span class="qf-btn-label">No-Show ↩</span>
            </button>
        </div>
        <p class="px-5 pb-4 text-[10px] text-slate-400">Call Again keeps the same ticket in place with a fresh deadline. No-Show closes it permanently and frees the window for the next student.</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function toast(msg, ok) {
        var wrap = document.getElementById('qfToast');
        var inner = document.getElementById('qfToastInner');
        if (!wrap || !inner) { return; }
        inner.textContent = msg;
        inner.className = 'rounded-xl px-4 py-2.5 text-xs font-semibold shadow-lg border ' +
            (ok ? 'bg-emerald-600 text-white border-emerald-700' : 'bg-red-600 text-white border-red-700');
        wrap.classList.remove('hidden');
        clearTimeout(wrap._t);
        wrap._t = setTimeout(function () { wrap.classList.add('hidden'); }, 4200);
    }

    /* Prevent double-submit on classic forms. */
    document.querySelectorAll('.qf-guard-double').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type="submit"]');
            if (b) { b.disabled = true; }
        });
    });

    /* Staff-picked duration stays in sync. */
    var durationInput = document.getElementById('qfCallDuration');
    var preset = document.getElementById('qfCallDurationPreset');
    function clampDuration(v) {
        v = parseInt(v, 10);
        if (isNaN(v)) { return 5; }
        return Math.min(60, Math.max(1, v));
    }
    if (durationInput && preset) {
        preset.addEventListener('change', function () {
            durationInput.value = clampDuration(preset.value);
            syncPwdDurations();
        });
        durationInput.addEventListener('input', function () {
            var v = clampDuration(durationInput.value);
            var opt = preset.querySelector('option[value="' + v + '"]');
            preset.value = opt ? String(v) : preset.value;
            syncPwdDurations();
        });
    }
    function syncPwdDurations() {
        if (!durationInput) { return; }
        document.querySelectorAll('.qf-pwd-duration').forEach(function (el) {
            el.value = durationInput.value;
        });
    }
    syncPwdDurations();

    /* Professional countdown: server expires_at is the single clock. */
    var countBox = document.getElementById('qfCountdownBox');
    var label = document.getElementById('qfCountdown');
    var note = document.getElementById('qfCountdownNote');
    var badge = document.getElementById('qfCountdownBadge');
    var statusBadge = document.getElementById('qfStatusBadge');
    var bar = document.getElementById('qfProgressBar');
    var barWrap = document.getElementById('qfProgressWrap');
    var meta = document.getElementById('qfCountdownMeta');
    var serverTimeEl = document.getElementById('qfServerTime');
    var countLabel = document.getElementById('qfCountdownLabel');

    var isArrived = countBox && countBox.getAttribute('data-arrived') === '1';
    var expiresMs = countBox ? Date.parse(countBox.getAttribute('data-expires-at') || '') : NaN;
    var totalSecs = 0;
    if (countBox) {
        var dur = parseInt(countBox.getAttribute('data-duration') || '5', 10);
        if (isNaN(dur) || dur < 1) { dur = 5; }
        totalSecs = dur * 60;
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function setTone(kind) {
        if (!countBox || !label) { return; }
        countBox.classList.remove('!border-amber-300', '!bg-amber-50', '!border-orange-400', '!bg-orange-50', '!border-red-300', '!bg-red-50', 'border-emerald-300', 'bg-emerald-50');
        label.classList.remove('!text-amber-700', '!text-orange-600', '!text-red-700', 'text-slate-900');
        if (bar) { bar.classList.remove('bg-blue-500', 'bg-amber-500', 'bg-orange-500', 'bg-red-500', 'bg-emerald-500'); }
        if (kind === 'warn') {
            countBox.classList.add('!border-amber-300', '!bg-amber-50');
            label.classList.add('!text-amber-700');
            if (bar) { bar.classList.add('bg-amber-500'); }
            if (badge) { badge.textContent = '1 min left'; badge.className = 'badge badge-waiting'; }
            if (statusBadge) { statusBadge.textContent = 'time almost up'; statusBadge.className = 'badge badge-waiting'; }
        } else if (kind === 'urgent') {
            countBox.classList.add('!border-orange-400', '!bg-orange-50');
            label.classList.add('!text-orange-600');
            if (bar) { bar.classList.add('bg-orange-500'); }
            if (badge) { badge.textContent = 'almost up'; badge.className = 'badge badge-waiting'; }
            if (statusBadge) { statusBadge.textContent = 'time almost up'; statusBadge.className = 'badge badge-waiting'; }
        } else if (kind === 'expired') {
            countBox.classList.add('!border-red-300', '!bg-red-50');
            label.classList.add('!text-red-700');
            if (bar) { bar.classList.add('bg-red-500'); }
            if (badge) { badge.textContent = 'expired'; badge.className = 'badge badge-cancelled'; }
            if (statusBadge) { statusBadge.textContent = 'time expired'; statusBadge.className = 'badge badge-cancelled'; }
        } else {
            if (bar) { bar.classList.add('bg-blue-500'); }
            if (badge && !isArrived) { badge.textContent = 'live'; badge.className = 'badge badge-serving'; }
            if (statusBadge && !isArrived) { statusBadge.textContent = 'serving'; statusBadge.className = 'badge badge-serving'; }
        }
    }

    /* Expiry modal state: one popup per ticket+deadline, never duplicated. */
    var modal = document.getElementById('qfExpiryModal');
    var mTicket = document.getElementById('qfModalTicket');
    var mStudent = document.getElementById('qfModalStudent');
    var mOffice = document.getElementById('qfModalOffice');
    var mMeta = document.getElementById('qfModalMeta');
    var mError = document.getElementById('qfModalError');
    var btnRecall = document.getElementById('qfModalRecall');
    var btnNoShow = document.getElementById('qfModalNoShow');
    var shownFor = null;
    var acting = false;
    var currentTicketId = countBox ? countBox.getAttribute('data-ticket-id') : null;

    function modalKey() {
        if (!countBox) { return null; }
        return (countBox.getAttribute('data-ticket-id') || '') + '|' + (countBox.getAttribute('data-expires-at') || '');
    }

    function openModal() {
        if (!modal || !countBox || isArrived) { return; }
        var key = modalKey();
        if (!key || shownFor === key) { return; }
        shownFor = key;
        if (mTicket) { mTicket.textContent = countBox.getAttribute('data-queue-number') || '—'; }
        if (mStudent) { mStudent.textContent = countBox.getAttribute('data-student') || '—'; }
        if (mOffice) { mOffice.textContent = countBox.getAttribute('data-office') || '—'; }
        if (mMeta) {
            var dur2 = countBox.getAttribute('data-duration') || '5';
            mMeta.textContent = 'Called ' + (countBox.getAttribute('data-called-at') || '—') + ' · ' + dur2 + ' min call' +
                (countBox.getAttribute('data-recall-count') && parseInt(countBox.getAttribute('data-recall-count'), 10) > 0 ? ' · recalled ' + countBox.getAttribute('data-recall-count') + '×' : '');
        }
        if (mError) { mError.classList.add('hidden'); mError.textContent = ''; }
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal() {
        if (!modal) { return; }
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function setActing(on, which) {
        acting = on;
        [btnRecall, btnNoShow].forEach(function (b) {
            if (!b) { return; }
            b.disabled = on;
            var lab = b.querySelector('.qf-btn-label');
            if (lab && on) {
                lab.dataset.orig = lab.dataset.orig || lab.textContent;
                lab.textContent = (b === which ? 'Working…' : lab.dataset.orig);
            } else if (lab && lab.dataset.orig) {
                lab.textContent = lab.dataset.orig;
            }
        });
    }

    function postAction(url, body, onOk) {
        if (acting) { return; }
        setActing(true, this);
        if (mError) { mError.classList.add('hidden'); }
        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, json: j }; }).catch(function () { return { ok: r.ok, json: {} }; });
        }).then(function (res) {
            setActing(false, null);
            if (res.ok) {
                toast(res.json.message || 'Done.', true);
                closeModal();
                setTimeout(function () { window.location.reload(); }, 650);
                if (onOk) { onOk(res.json); }
            } else {
                var msg = (res.json && res.json.message) || 'Action failed. Please try again.';
                if (mError) { mError.textContent = msg; mError.classList.remove('hidden'); }
                toast(msg, false);
            }
        }).catch(function () {
            setActing(false, null);
            if (mError) { mError.textContent = 'No connection. Reconnect and try again.'; mError.classList.remove('hidden'); }
            toast('No connection. Reconnect and try again.', false);
        });
    }

    if (btnRecall) {
        btnRecall.addEventListener('click', function () {
            if (!currentTicketId) { return; }
            var dur3 = countBox ? parseInt(countBox.getAttribute('data-duration') || '5', 10) : 5;
            postAction.call(btnRecall, '/staff/queues/' + currentTicketId + '/recall', { call_duration_minutes: dur3 });
        });
    }
    if (btnNoShow) {
        btnNoShow.addEventListener('click', function () {
            if (!currentTicketId) { return; }
            if (!confirm('Mark this ticket as No-Show? It leaves the queue permanently.')) { return; }
            postAction.call(btnNoShow, '/staff/queues/' + currentTicketId + '/no-show', {});
        });
    }
    if (modal) {
        modal.querySelectorAll('[data-qf-modal-dismiss]').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeModal(); }
        });
    }

    function tick() {
        if (!countBox || !label) { return; }
        if (isArrived) { return; }
        if (isNaN(expiresMs)) {
            var calledRaw = countBox.getAttribute('data-called-at') || '';
            var calledMs = Date.parse(calledRaw);
            if (isNaN(calledMs)) { label.textContent = '--:--'; return; }
            var up = Math.max(0, Math.floor((Date.now() - calledMs) / 1000));
            label.textContent = pad(Math.floor(up / 60)) + ':' + pad(up % 60);
            if (note) { note.textContent = 'Serving — no deadline set for this call'; }
            if (bar) { bar.style.width = '100%'; }
            if (barWrap) { barWrap.setAttribute('aria-valuenow', '100'); }
            return;
        }
        var left = Math.max(0, Math.floor((expiresMs - Date.now()) / 1000));
        if (left > 0) {
            label.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
            if (meta) { meta.textContent = left <= 60 ? left + 's left' : Math.ceil(left / 60) + ' min left'; }
            if (note && !note.dataset.locked) {
                note.textContent = left <= 60
                    ? 'Warning: 1 minute remaining — wrap up or prepare Call Again / No-Show.'
                    : left <= 120
                        ? 'Less than 2 minutes remaining.'
                        : ('Called ' + (new Date(Date.parse(countBox.getAttribute('data-called-at') || '')).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}) || ''));
            }
            var pct = totalSecs > 0 ? Math.max(0, Math.min(100, Math.round(left / totalSecs * 100))) : 0;
            if (bar) { bar.style.width = pct + '%'; }
            if (barWrap) { barWrap.setAttribute('aria-valuenow', String(pct)); }
            if (left <= 30) { setTone('urgent'); }
            else if (left <= 60) { setTone('warn'); }
            else { setTone('live'); }
        } else {
            label.textContent = 'TIME EXPIRED';
            if (meta) { meta.textContent = '0:00'; }
            if (bar) { bar.style.width = '0%'; }
            if (barWrap) { barWrap.setAttribute('aria-valuenow', '0'); }
            setTone('expired');
            if (note) { note.textContent = 'Deadline passed — choose Call Again or No-Show below.'; note.dataset.locked = '1'; }
            openModal();
        }
    }

    if (countBox && !isArrived) {
        tick();
        setInterval(tick, 1000);
    }

    /* Server poll: the single source of truth. Picks up recalls / no-shows /
       arrivals from other devices, corrects clock skew, and raises the popup
       even if this tab was asleep when the deadline passed. */
    var stateUrl = '<?php echo e(route('staff.queues.state')); ?>';
    var pollFailures = 0;

    /* Live sync: in-place waiting-list + batch updates from the same poll.
       Structural changes (serving transitions, batch deploy/close/complete)
       still reload, but only while the staff member is idle — typing,
       an open modal, or an in-flight action defers to a Refresh banner so
       no input, dialog, or scroll position is ever lost. */
    var qfShowNames = <?php echo e($showNames ? 'true' : 'false'); ?>;
    var qfCallTimeoutDefault = <?php echo e((int) ($callTimeout ?? \App\Models\QueueTicket::DEFAULT_CALL_DURATION_MINUTES)); ?>;
    var qfCallNextUrl = '<?php echo e(route('staff.queues.call-next')); ?>';
    var qfEvidenceBase = '<?php echo e(url('staff/queues/evidence')); ?>';
    var qfLastWaitingIds = null;
    var qfLastWaitingCount = -1;
    var pendingReload = null;

    function qfEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function qfSetText(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = value; }
    }

    function isUserBusy() {
        if (acting) { return true; }
        if (modal && !modal.classList.contains('hidden')) { return true; }
        var ae = document.activeElement;
        if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT')) { return true; }
        return false;
    }

    function showLiveBanner(msg) {
        var banner = document.getElementById('qfLiveBanner');
        var text = document.getElementById('qfLiveBannerText');
        if (text && msg) { text.textContent = msg; }
        if (banner) { banner.classList.remove('hidden'); }
    }

    function hideLiveBanner() {
        var banner = document.getElementById('qfLiveBanner');
        if (banner) { banner.classList.add('hidden'); }
    }

    function safeReload(reason) {
        if (isUserBusy()) {
            pendingReload = reason || 'Queue updated.';
            showLiveBanner(pendingReload);
            return;
        }
        pendingReload = null;
        window.location.reload();
    }

    var qfRefreshBtn = document.getElementById('qfLiveRefreshBtn');
    if (qfRefreshBtn) {
        qfRefreshBtn.addEventListener('click', function () {
            pendingReload = null;
            window.location.reload();
        });
    }

    function qfLiveMarkHealthy() {
        var off = document.getElementById('qfLiveOffline');
        if (off) { off.classList.add('hidden'); }
        var upd = document.getElementById('qfLiveUpdated');
        if (upd) {
            try { upd.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }
            catch (e) { upd.textContent = 'Updated just now'; }
        }
    }

    function qfLiveMarkUnhealthy() {
        if (pollFailures < 2) { return; }
        var off = document.getElementById('qfLiveOffline');
        if (off) { off.classList.remove('hidden'); }
    }

    function qfRenderWaiting(tickets, hasServing, deploymentStatus) {
        var list = document.getElementById('qfWaitingList');
        if (!list) { return; }
        if (!tickets || !tickets.length) {
            if (deploymentStatus === 'deployed') {
                list.innerHTML = '<div class="py-6 text-center"><div class="text-2xl mb-2">🚶</div>' +
                    '<p class="text-sm font-semibold text-slate-700">No students in line right now.</p>' +
                    '<p class="text-xs text-slate-400 mt-1">This batch is deployed for serving, so intake is closed.</p></div>';
            } else {
                list.innerHTML = '<div class="py-6 text-center"><div class="text-2xl mb-2">🎛️</div>' +
                    '<p class="text-sm font-semibold text-slate-700">The batch is not accepting tickets yet.</p>' +
                    '<p class="text-xs text-slate-400 mt-1">Deploy the batch above to open slots and start queueing students.</p></div>';
            }
            return;
        }
        var html = tickets.map(function (t, i) {
            var row = '<div class="flex items-center justify-between gap-3 py-2.5 border-b border-slate-100 last:border-0" data-ticket-id="' + t.id + '">';
            row += '<div class="flex items-center gap-3 min-w-0">';
            row += '<span class="flex-shrink-0 w-7 h-7 rounded-full ' + (i === 0 ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-500') + ' text-xs font-bold flex items-center justify-center">' + (i + 1) + '</span>';
            row += '<div class="min-w-0"><div class="flex items-center gap-1.5 flex-wrap">';
            row += '<span class="font-bold text-blue-800">' + qfEsc(t.queue_number) + '</span>';
            if (qfShowNames) { row += '<span class="text-sm font-semibold text-slate-700 truncate">' + qfEsc(t.student_name || 'Walk-in') + '</span>'; }
            if (t.priority === 'pwd') { row += '<span class="badge badge-pwd">PWD</span>'; }
            if (t.course_code) { row += '<span class="text-[10px] font-semibold text-slate-400 uppercase">' + qfEsc(t.course_code) + '</span>'; }
            row += '</div>';
            if (t.purpose) { row += '<div class="text-[11px] text-slate-400 mt-0.5 truncate">' + qfEsc(t.purpose) + '</div>'; }
            row += '</div></div>';
            row += '<div class="flex items-center gap-2 flex-shrink-0">';
            if (t.priority === 'pwd' && t.has_evidence_path) {
                row += '<a href="' + qfEvidenceBase + '/' + t.id + '" target="_blank" class="text-xs text-blue-600 underline">evidence</a>';
            }
            if (t.priority === 'pwd' && t.has_evidence) {
                row += '<form method="POST" action="' + qfCallNextUrl + '" class="qf-pwd-call-form" onsubmit="this.querySelector(\'button\').disabled = true;">' +
                    '<input type="hidden" name="_token" value="' + csrf + '">' +
                    '<input type="hidden" name="ticket_id" value="' + t.id + '">' +
                    '<input type="hidden" name="call_duration_minutes" class="qf-pwd-duration" value="' + qfCallTimeoutDefault + '">' +
                    '<button type="submit" class="btn-primary text-xs"' + (hasServing ? ' disabled title="Finish the ticket being served first"' : '') + '>▶ Call Next</button></form>';
            }
            if (i === 0 && !hasServing) { row += '<span class="badge badge-waiting">next</span>'; }
            row += '</div></div>';
            return row;
        }).join('');
        list.innerHTML = html;
        if (typeof syncPwdDurations === 'function') { syncPwdDurations(); }
    }

    function qfLiveSync(data) {
        var tickets = data.waiting_tickets || [];
        var freshIds = tickets.map(function (t) { return String(t.id); }).join(',');
        if (qfLastWaitingIds === null) {
            var current = [];
            document.querySelectorAll('#qfWaitingList [data-ticket-id]').forEach(function (el) {
                current.push(String(el.getAttribute('data-ticket-id')));
            });
            qfLastWaitingIds = current.join(',');
            qfLastWaitingCount = current.length;
        }
        if (freshIds !== qfLastWaitingIds) {
            qfRenderWaiting(tickets, !!data.now_serving, data.deployment ? data.deployment.status : null);
            qfSetText('qfWaitingCount', '(' + tickets.length + ')');
            if (tickets.length > qfLastWaitingCount) {
                toast('New student in line — list updated.', true);
            }
            qfLastWaitingIds = freshIds;
            qfLastWaitingCount = tickets.length;
        }
        var dep = data.deployment || null;
        var card = document.getElementById('qfBatchCard');
        var pageKey = card ? card.getAttribute('data-deployment-key') : 'none';
        var srvKey = dep ? (dep.id + '|' + dep.status + '|' + dep.batch_no) : 'none';
        if (srvKey !== pageKey) {
            safeReload('Batch updated — refreshing to show the current controls.');
            return;
        }
        if (dep) {
            var limit = dep.max_slots > 0 ? dep.max_slots : 100;
            var pct = Math.max(0, Math.min(100, Math.round(dep.submitted / limit * 100)));
            qfSetText('qfBatchSubmitted', dep.submitted);
            qfSetText('qfBatchWaiting', data.waiting_count);
            qfSetText('qfBatchServing', data.now_serving ? 1 : 0);
            qfSetText('qfBatchServed', dep.served);
            qfSetText('qfBatchSlots', dep.submitted + ' / ' + limit + ' slots used');
            qfSetText('qfBatchServedTxt', dep.served + ' served');
            var bar = document.getElementById('qfBatchBar');
            if (bar) {
                bar.style.width = pct + '%';
                bar.classList.remove('bg-blue-500', 'bg-amber-500');
                bar.classList.add(pct >= 100 ? 'bg-amber-500' : 'bg-blue-500');
            }
        }
    }

    function pollState() {
        if (document.hidden) { return; }
        fetch(stateUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { pollFailures++; qfLiveMarkUnhealthy(); return; }
                pollFailures = 0;
                qfLiveMarkHealthy();
                qfLiveSync(data);
                if (pendingReload && !isUserBusy()) {
                    pendingReload = null;
                    hideLiveBanner();
                    window.location.reload();
                    return;
                }
                var srv = data.now_serving || null;
                if (serverTimeEl && data.server_time) {
                    try { serverTimeEl.textContent = 'server ' + new Date(data.server_time).toLocaleTimeString([], {hour:'numeric',minute:'2-digit',second:'2-digit'}); } catch (e) {}
                }
                if (!srv && countBox && !isArrived) {
                    /* Serving ticket resolved elsewhere (served / no-show /
                       cancelled): reload once to reflect the freed window. */
                    if (currentTicketId) {
                        toast('Queue updated by another action. Refreshing…', true);
                        currentTicketId = null;
                        safeReload('Queue updated by another action.');
                    }
                    return;
                }
                if (srv && countBox && String(srv.id) === String(currentTicketId)) {
                    var newExp = srv.expires_at || '';
                    if (newExp !== (countBox.getAttribute('data-expires-at') || '')) {
                        countBox.setAttribute('data-expires-at', newExp);
                        countBox.setAttribute('data-called-at', srv.called_at || '');
                        countBox.setAttribute('data-duration', String(srv.call_duration_minutes || 5));
                        countBox.setAttribute('data-recall-count', String(srv.recall_count || 0));
                        expiresMs = Date.parse(newExp);
                        var d = parseInt(srv.call_duration_minutes || '5', 10);
                        totalSecs = (isNaN(d) || d < 1 ? 5 : d) * 60;
                        if (note) { delete note.dataset.locked; }
                        shownFor = null;
                        if (countLabel) { countLabel.textContent = 'Time remaining (' + (srv.call_duration_minutes || 5) + ' min call)'; }
                        tick();
                    }
                    if (srv.is_arrived && !isArrived) {
                        safeReload('Student arrived — refreshing.');
                        return;
                    }
                    if (srv.is_expired) {
                        setTone('expired');
                        if (label) { label.textContent = 'TIME EXPIRED'; }
                        openModal();
                    }
                } else if (srv && (!countBox || !currentTicketId)) {
                    /* A new call started elsewhere while this page showed idle. */
                    safeReload('A new call started — refreshing.');
                }
            })
            .catch(function () {
                pollFailures++;
                qfLiveMarkUnhealthy();
                /* Offline: keep the local 1s countdown running from the last
                   server deadline; resync automatically on reconnect. */
            });
    }
    setInterval(pollState, 5000);
    setTimeout(pollState, 2500);

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

    /* Keep the cancellation card fresh without a full page reload. */
    var box = document.getElementById('qfCancelAlerts');
    if (box) {
        var feedUrl = '<?php echo e(route('staff.notifications.recent')); ?>';
        var detailBase = '<?php echo e(url('staff/notifications')); ?>';
        function esc(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        setInterval(function () {
            fetch(feedUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !Array.isArray(data.notifications)) { return; }
                    var items = data.notifications.slice(0, 5);
                    if (!items.length) { return; }
                    box.innerHTML = items.map(function (n) {
                        var when = n.cancelled_at || n.created_at || '';
                        var detail = when;
                        try { detail = when ? new Date(when).toLocaleString() : ''; } catch (e) {}
                        return '<div class="flex items-start justify-between gap-3 py-2.5 border-b border-slate-100 last:border-0">' +
                            '<div class="min-w-0"><div class="flex items-center gap-1.5 flex-wrap">' +
                            '<span class="font-bold text-red-700">' + esc(n.queue_number) + '</span>' +
                            '<span class="text-xs font-semibold text-slate-700">' + esc(n.student_name) + '</span>' +
                            (n.is_read ? '' : '<span class="badge badge-cancelled">new</span>') +
                            '</div><div class="text-[11px] text-slate-500 mt-0.5">' + esc(n.message) + '</div>' +
                            '<div class="text-[10px] text-slate-400 mt-0.5">' + esc(n.purpose || 'No purpose stated') + ' · ' + esc(n.priority || 'regular') + ' · ' + esc(detail) + '</div></div>' +
                            '<a href="' + detailBase + '/' + n.id + '" class="flex-shrink-0 text-xs text-blue-600 underline">details</a></div>';
                    }).join('');
                })
                .catch(function () {});
        }, 15000);
    }
});
</script>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.staff', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/staff/dashboard.blade.php ENDPATH**/ ?>