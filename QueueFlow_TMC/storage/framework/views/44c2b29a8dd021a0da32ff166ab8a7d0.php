
<div id="qfStaleBanner" class="hidden card p-3 mb-4 items-center justify-between gap-3" style="border-color:#bfdbfe;background:#eff6ff;display:none;" role="status" aria-live="polite">
    <span class="text-xs font-semibold text-blue-800" id="qfStaleBannerText">New updates are available.</span>
    <div class="flex gap-2 flex-shrink-0">
        <button type="button" id="qfStaleDismiss" class="btn-secondary text-xs">Dismiss</button>
        <button type="button" id="qfStaleRefresh" class="btn-primary text-xs">Refresh view</button>
    </div>
</div>
<script>
window.QfStaleBanner = window.QfStaleBanner || (function () {
    function banner() { return document.getElementById('qfStaleBanner'); }
    function show(message) {
        var el = banner();
        if (!el) { return; }
        var text = document.getElementById('qfStaleBannerText');
        if (text && message) { text.textContent = message; }
        el.classList.remove('hidden');
        el.style.display = 'flex';
    }
    function hide() {
        var el = banner();
        if (!el) { return; }
        el.classList.add('hidden');
        el.style.display = 'none';
    }
    function wire() {
        var dismiss = document.getElementById('qfStaleDismiss');
        if (dismiss && !dismiss.dataset.qfWired) {
            dismiss.dataset.qfWired = '1';
            dismiss.addEventListener('click', hide);
        }
        var refresh = document.getElementById('qfStaleRefresh');
        if (refresh && !refresh.dataset.qfWired) {
            refresh.dataset.qfWired = '1';
            refresh.addEventListener('click', function () { window.location.reload(); });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
    return { show: show, hide: hide, wire: wire };
})();
</script>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/partials/update-banner.blade.php ENDPATH**/ ?>