{{-- =========================================================
     STAFF — RESET BATCH NUMBER (shared trigger + confirm dialog)
     POSTs to staff.admissions.reset-batch-number, which is scoped to
     the staff member's own office. Shown on the Staff Dashboard batch
     controls and the My Office page; both use this one partial so the
     wording, dialog and double-submit guard cannot drift apart.
     ========================================================== --}}
<span data-qf-reset-batch style="display:contents">
    <button type="button" class="btn-secondary text-xs qf-reset-batch-open">Reset Batch Number</button>

    <div class="qf-modal-overlay qf-reset-batch-modal">
        <div class="qf-modal-card" role="dialog" aria-modal="true" aria-labelledby="qfResetBatchTitle">
            <h2 class="qf-modal-title" id="qfResetBatchTitle">Reset Batch Number?</h2>
            <p class="qf-modal-text">Are you sure you want to reset your office batch number to Batch 1? This action should be performed when closing or setting your office offline.</p>
            <form method="POST" action="{{ route('staff.admissions.reset-batch-number') }}" class="qf-reset-batch-form">
                @csrf
                <div class="qf-modal-actions">
                    <button type="button" class="qf-modal-cancel qf-reset-batch-cancel">Cancel</button>
                    <button type="submit" class="qf-modal-confirm qf-reset-batch-confirm">Confirm Reset</button>
                </div>
            </form>
        </div>
    </div>
</span>
<script>
(function () {
    function initResetBatch(scope) {
        var openBtn = scope.querySelector('.qf-reset-batch-open');
        var modal = scope.querySelector('.qf-reset-batch-modal');
        var cancelBtn = scope.querySelector('.qf-reset-batch-cancel');
        var form = scope.querySelector('.qf-reset-batch-form');
        var confirmBtn = scope.querySelector('.qf-reset-batch-confirm');
        if (!openBtn || !modal || !form || !confirmBtn) { return; }

        function openModal() {
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal() {
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        }

        openBtn.addEventListener('click', openModal);
        if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) { closeModal(); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeModal(); }
        });

        /* Prevent repeated clicks while the request is processing. */
        form.addEventListener('submit', function () {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Resetting…';
            openBtn.disabled = true;
        });
    }

    function bootResetBatch() {
        document.querySelectorAll('[data-qf-reset-batch]').forEach(initResetBatch);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootResetBatch);
    } else {
        bootResetBatch();
    }
})();
</script>
