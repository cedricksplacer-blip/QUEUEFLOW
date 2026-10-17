<?php if($paginator->total() > 0): ?>
    
    <style>
        .qf-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .qf-pagination__info { font-size: 12px; color: #64748b; margin: 0; }
        .qf-pagination__info strong { color: #1d4ed8; font-weight: 700; }
        .qf-pagination nav { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .qf-page-link {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 34px; padding: 0 10px;
            border-radius: 9px; border: 1px solid #cbd5e1;
            background: #fff; color: #334155;
            font-size: 12px; font-weight: 700; text-decoration: none;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease, transform .15s ease;
        }
        a.qf-page-link:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
        a.qf-page-link:active { transform: scale(.95); }
        a.qf-page-link:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(245,158,11,.45); border-color: #f59e0b; }
        .qf-page-link--edge { padding: 0 12px; border-color: #bfdbfe; color: #1d4ed8; background: #fff; }
        .qf-page-link--active {
            background: #2563eb; border-color: #1d4ed8; color: #fff;
            box-shadow: inset 0 -3px 0 #f59e0b, 0 1px 3px rgba(37,99,235,.35);
        }
        .qf-page-link--disabled { background: #f8fafc; border-color: #e2e8f0; color: #cbd5e1; cursor: not-allowed; }
        .qf-page-gap { color: #94a3b8; padding: 0 2px; user-select: none; }
        @media (max-width: 640px) {
            .qf-pagination { flex-direction: column; align-items: stretch; text-align: center; }
            .qf-pagination nav { justify-content: center; }
            .qf-page-link { min-width: 32px; height: 32px; font-size: 11px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .qf-page-link { transition: none; }
        }
        html.dark .qf-pagination__info { color: #94a3b8; }
        html.dark .qf-pagination__info strong { color: #93c5fd; }
        html.dark .qf-page-link { background: #0b1120; border-color: #334155; color: #cbd5e1; }
        html.dark a.qf-page-link:hover { background: #16233d; border-color: #3b82f6; color: #93c5fd; }
        html.dark .qf-page-link--edge { border-color: #33507a; color: #93c5fd; }
        html.dark .qf-page-link--active { background: #2563eb; border-color: #3b82f6; color: #fff; }
        html.dark .qf-page-link--disabled { background: #111c30; border-color: #1e293b; color: #475569; }
        html.dark .qf-page-gap { color: #475569; }
    </style>
    <div class="qf-pagination">
        <p class="qf-pagination__info">
            Showing <?php echo e($paginator->firstItem()); ?> to <?php echo e($paginator->lastItem()); ?> of <?php echo e($paginator->total()); ?> results
        </p>
        <?php if($paginator->hasPages()): ?>
            <nav class="qf-pagination__nav" role="navigation" aria-label="Queue history pages">
                
                <?php if($paginator->onFirstPage()): ?>
                    <span class="qf-page-link qf-page-link--edge qf-page-link--disabled" aria-disabled="true" aria-label="Previous page">&laquo; Previous</span>
                <?php else: ?>
                    <a class="qf-page-link qf-page-link--edge" href="<?php echo e($paginator->previousPageUrl()); ?>" rel="prev" aria-label="Previous page">&laquo; Previous</a>
                <?php endif; ?>

                
                <?php $__currentLoopData = $elements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $element): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php if(is_string($element)): ?>
                        <span class="qf-page-gap" aria-hidden="true"><?php echo e($element); ?></span>
                    <?php endif; ?>

                    <?php if(is_array($element)): ?>
                        <?php $__currentLoopData = $element; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $page => $url): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if($page == $paginator->currentPage()): ?>
                                <span class="qf-page-link qf-page-link--active" aria-current="page"><?php echo e($page); ?></span>
                            <?php else: ?>
                                <a class="qf-page-link" href="<?php echo e($url); ?>" aria-label="Go to page <?php echo e($page); ?>"><?php echo e($page); ?></a>
                            <?php endif; ?>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    <?php endif; ?>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                
                <?php if($paginator->hasMorePages()): ?>
                    <a class="qf-page-link qf-page-link--edge" href="<?php echo e($paginator->nextPageUrl()); ?>" rel="next" aria-label="Next page">Next &raquo;</a>
                <?php else: ?>
                    <span class="qf-page-link qf-page-link--edge qf-page-link--disabled" aria-disabled="true" aria-label="Next page">Next &raquo;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/vendor/pagination/queueflow.blade.php ENDPATH**/ ?>