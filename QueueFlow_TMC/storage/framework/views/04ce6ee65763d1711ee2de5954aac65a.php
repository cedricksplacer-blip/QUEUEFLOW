
<div class="qf-act-row">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold text-slate-900"><?php echo e($ticket->queue_number); ?></span>
                <span class="badge badge-<?php echo e($ticket->status === 'no_show' ? 'cancelled' : $ticket->status); ?>"><?php echo e($ticket->status === 'no_show' ? 'No-Show' : $ticket->status); ?></span>
                <?php if($ticket->isPwd()): ?>
                    
                    <button
                        type="button"
                        class="js-pwd-proof badge badge-pwd qf-pwd-badge"
                        title="<?php echo e(filled($ticket->priority_evidence) ? 'View PWD verification proof' : 'No PWD proof picture available for this student.'); ?>"
                        aria-label="View PWD verification proof for queue <?php echo e($ticket->queue_number); ?>"
                        data-url="<?php echo e(filled($ticket->priority_evidence) ? route('admin.queues.evidence', $ticket) : ''); ?>"
                        data-student="<?php echo e($ticket->student->name ?? 'Walk-in'); ?>"
                        data-student-id="<?php echo e($ticket->student->student_id ?? ''); ?>"
                        data-office="<?php echo e($ticket->office->name ?? ''); ?>"
                        data-queue="<?php echo e($ticket->queue_number); ?>"
                        data-purpose="<?php echo e($ticket->purpose ?? ''); ?>"
                        data-status="<?php echo e($ticket->status); ?>"
                        data-time="<?php echo e(optional($ticket->created_at)->format('M d, Y h:i A') ?? ''); ?>"
                    >PWD</button>
                <?php endif; ?>
            </div>
            <div class="text-xs font-semibold text-slate-700 mt-1 truncate">
                <?php echo e($ticket->student->name ?? 'Walk-in'); ?>

                <?php if(!empty($ticket->student?->student_id)): ?>
                    <span class="font-normal text-slate-400">(<?php echo e($ticket->student->student_id); ?>)</span>
                <?php endif; ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">
                <?php echo e($ticket->office->name ?? 'N/A'); ?>

                <?php if($ticket->course): ?> · <?php echo e($ticket->course->code); ?><?php endif; ?>
                · <?php echo e($ticket->purpose ?? 'No purpose stated'); ?>

            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">
                <?php echo e(optional($ticket->created_at)->format('M d, h:i A') ?? '—'); ?>

            </div>
        </div>
    </div>
</div>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/partials/activity-ticket-row.blade.php ENDPATH**/ ?>