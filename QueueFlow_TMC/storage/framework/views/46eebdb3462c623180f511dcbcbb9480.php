
<style>
    /* The trigger is the PWD badge itself; only reset what a <button> needs
       so the .badge pill (size, border, weight) renders exactly as the
       non-clickable badge. */
    button.js-pwd-proof { cursor: pointer; font-family: inherit; }
    .qf-pwd-badge { transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
    .qf-pwd-badge:hover { filter: brightness(.96); box-shadow: 0 0 0 3px rgba(217, 119, 6, .18); transform: translateY(-1px); }
    .qf-pwd-badge:active { transform: translateY(0); }
    .qf-pwd-badge:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .qf-pwd-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, .62); padding: 16px; animation: qf-pwd-fade .16s ease; }
    .qf-pwd-overlay[hidden] { display: none !important; }
    .qf-pwd-overlay.qf-pwd-closing { animation: qf-pwd-fade-out .16s ease forwards; }
    .qf-pwd-card { width: 100%; max-width: 560px; max-height: 92vh; overflow: auto; border-radius: 14px; background: #fff; box-shadow: 0 24px 48px rgba(0, 0, 0, .35); animation: qf-pwd-pop .22s cubic-bezier(.22, 1, .36, 1); }
    .qf-pwd-overlay.qf-pwd-closing .qf-pwd-card { animation: none; }
    @keyframes qf-pwd-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes qf-pwd-fade-out { from { opacity: 1; } to { opacity: 0; } }
    @keyframes qf-pwd-pop { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .qf-pwd-overlay, .qf-pwd-overlay.qf-pwd-closing, .qf-pwd-card { animation: none !important; }
        .qf-pwd-badge { transition: none; }
    }
    .qf-pwd-card.qf-zoomed { max-width: 920px; }
    .qf-pwd-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
    .qf-pwd-title { font-size: 14px; font-weight: 800; color: #0f172a; }
    .qf-pwd-meta { margin-top: 4px; font-size: 11px; color: #64748b; line-height: 1.6; }
    .qf-pwd-close { flex-shrink: 0; width: 30px; height: 30px; border-radius: 9999px; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-size: 16px; line-height: 1; cursor: pointer; }
    .qf-pwd-close:hover { background: #f1f5f9; }
    .qf-pwd-body { padding: 16px; text-align: center; background: #f8fafc; }
    .qf-pwd-body img { max-width: 100%; max-height: 58vh; border-radius: 10px; border: 1px solid #e2e8f0; cursor: zoom-in; }
    .qf-pwd-card.qf-zoomed .qf-pwd-body img { max-height: 80vh; cursor: zoom-out; }
    .qf-pwd-body img[hidden] { display: none !important; }
    .qf-pwd-error { margin: 0; padding: 22px 12px; font-size: 12px; color: #94a3b8; }
    .qf-pwd-error[hidden] { display: none !important; }
    .qf-pwd-actions { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 16px 16px; }
    .qf-pwd-actions [hidden] { display: none !important; }
    .qf-pwd-btn { border-radius: 8px; padding: 7px 14px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; }
    .qf-pwd-zoom { border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .qf-pwd-zoom:hover { background: #f8fafc; }
    .qf-pwd-full { border: 1px solid #1d4ed8; background: #2563eb; color: #fff; }
    .qf-pwd-full:hover { background: #1d4ed8; }
    html.dark .qf-pwd-card { background: #1e293b; }
    html.dark .qf-pwd-head { border-color: #334155; }
    html.dark .qf-pwd-title { color: #f8fafc; }
    html.dark .qf-pwd-meta { color: #94a3b8; }
    html.dark .qf-pwd-close { background: #0b1120; border-color: #334155; color: #e2e8f0; }
    html.dark .qf-pwd-body { background: #0b1120; }
    html.dark .qf-pwd-body img { border-color: #334155; }
    html.dark .qf-pwd-zoom { background: #1e293b; border-color: #475569; color: #e2e8f0; }
</style>
<div id="pwdProofModal" class="qf-pwd-overlay" hidden>
    <div id="pwdProofCard" class="qf-pwd-card" role="dialog" aria-modal="true" aria-label="PWD verification proof">
        <div class="qf-pwd-head">
            <div class="min-w-0">
                <div class="qf-pwd-title">PWD Verification Proof</div>
                <div id="pwdProofMeta" class="qf-pwd-meta"></div>
            </div>
            <button type="button" id="pwdProofClose" class="qf-pwd-close" aria-label="Close proof viewer">×</button>
        </div>
        <div class="qf-pwd-body">
            <img id="pwdProofImg" alt="PWD verification proof uploaded by the student" hidden>
            <p id="pwdProofError" class="qf-pwd-error" hidden>No PWD proof picture available for this student.</p>
        </div>
        <div class="qf-pwd-actions">
            <button type="button" id="pwdProofZoom" class="qf-pwd-btn qf-pwd-zoom">Enlarge</button>
            <a id="pwdProofFull" class="qf-pwd-btn qf-pwd-full" href="#" target="_blank" rel="noopener">Open full size</a>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('pwdProofModal');
    var card = document.getElementById('pwdProofCard');
    if (!modal || !card || modal.dataset.qfBound === '1') { return; }
    modal.dataset.qfBound = '1';

    var img = document.getElementById('pwdProofImg');
    var err = document.getElementById('pwdProofError');
    var meta = document.getElementById('pwdProofMeta');
    var zoomBtn = document.getElementById('pwdProofZoom');
    var fullLink = document.getElementById('pwdProofFull');
    var closeBtn = document.getElementById('pwdProofClose');

    function esc(value) {
        return String(value == null || value === '' ? '—' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var closeTimer = null;
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function resetView() {
        card.classList.remove('qf-zoomed');
        zoomBtn.textContent = 'Enlarge';
        img.hidden = true;
        img.removeAttribute('src');
    }

    function finishClose() {
        if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
        modal.classList.remove('qf-pwd-closing');
        modal.hidden = true;
        document.body.classList.remove('overflow-hidden');
    }

    function close() {
        if (modal.hidden || modal.classList.contains('qf-pwd-closing')) { return; }
        resetView();
        if (reduceMotion) { finishClose(); return; }
        modal.classList.add('qf-pwd-closing');
        closeTimer = window.setTimeout(finishClose, 170);
    }

    img.addEventListener('load', function () {
        err.hidden = true;
        img.hidden = false;
    });

    img.addEventListener('error', function () {
        img.hidden = true;
        err.hidden = false;
        zoomBtn.hidden = true;
        fullLink.hidden = true;
    });

    zoomBtn.addEventListener('click', function () {
        var zoomed = card.classList.toggle('qf-zoomed');
        zoomBtn.textContent = zoomed ? 'Shrink' : 'Enlarge';
    });

    img.addEventListener('click', function () {
        zoomBtn.click();
    });

    closeBtn.addEventListener('click', close);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) { close(); }
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('.js-pwd-proof') : null;
        if (!trigger) { return; }
        event.preventDefault();

        var d = trigger.dataset;
        meta.innerHTML =
            esc(d.student) + ' (' + esc(d.studentId) + ')<br>' +
            esc(d.queue) + ' · ' + esc(d.office) + ' · ' + esc(d.status) + '<br>' +
            esc(d.purpose) + ' · ' + esc(d.time);

        if (closeTimer) { window.clearTimeout(closeTimer); closeTimer = null; }
        modal.classList.remove('qf-pwd-closing');
        resetView();
        err.hidden = true;

        var url = d.url || '';
        if (url) {
            zoomBtn.hidden = false;
            fullLink.hidden = false;
            fullLink.href = url;
            img.src = url;
        } else {
            // PWD ticket with no uploaded proof: show the notice, no request.
            zoomBtn.hidden = true;
            fullLink.hidden = true;
            fullLink.href = '#';
            err.hidden = false;
        }

        modal.hidden = false;
        document.body.classList.add('overflow-hidden');
    });
})();
</script>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/partials/pwd-proof-modal.blade.php ENDPATH**/ ?>