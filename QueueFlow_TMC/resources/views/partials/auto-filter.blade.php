{{--
    QueueFlow TMC — Automatic dropdown filtering (shared partial).

    Include once per filter page with @include('partials.auto-filter'), then add
    data-qf-auto-filter to any GET filter <form>. Any <select> or <input type="date">
    change submits the form immediately; search text inputs submit after a short
    debounce. Combined selections are sent together via the normal GET query, so
    the existing Laravel controllers, API endpoints and role scopes keep working.

    Loading state: a small "Updating..." indicator appears and the form is marked
    aria-busy while navigating. Errors stay server-side (validation messages and
    empty states render as before).
--}}
<style>
    form[data-qf-auto-filter] { position: relative; }
    form[data-qf-auto-filter].is-filtering { opacity: .75; pointer-events: none; }
    .qf-auto-filter-status {
        display: none;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 600;
        color: #64748b;
        white-space: nowrap;
    }
    form[data-qf-auto-filter].is-filtering .qf-auto-filter-status { display: inline-flex; }
    .qf-auto-filter-spinner {
        width: 12px;
        height: 12px;
        flex-shrink: 0;
        border-radius: 9999px;
        border: 2px solid #cbd5e1;
        border-top-color: #2563eb;
        animation: qf-auto-filter-spin .7s linear infinite;
    }
    @keyframes qf-auto-filter-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .qf-auto-filter-spinner { animation: none; }
    }
</style>
<script>
(function () {
    function autoFilterSubmit(form) {
        if (!form || form.dataset.qfSubmitting === '1') { return; }
        try {
            form.dataset.qfSubmitting = '1';
            form.classList.add('is-filtering');
            form.setAttribute('aria-busy', 'true');
            var status = form.querySelector('[data-qf-filter-status]');
            if (status) { status.textContent = ''; }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        } catch (err) {
            form.dataset.qfSubmitting = '';
            form.classList.remove('is-filtering');
            form.removeAttribute('aria-busy');
        }
    }

    function bootAutoFilter() {
        document.querySelectorAll('form[data-qf-auto-filter]').forEach(function (form) {
            if (form.dataset.qfAutoFilterBooted === '1') { return; }
            form.dataset.qfAutoFilterBooted = '1';

            // Dropdowns + date pickers: apply immediately, combined with the
            // rest of the form's current selections.
            form.querySelectorAll('select, input[type="date"]').forEach(function (el) {
                el.addEventListener('change', function () {
                    autoFilterSubmit(form);
                });
            });

            // Search boxes: debounce so typing does not reload on every keystroke,
            // but changing a dropdown still applies instantly.
            var searchTimer = null;
            form.querySelectorAll('input[type="text"], input[type="search"], input:not([type]), input[name="search"]').forEach(function (el) {
                if (el.type === 'date' || el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'submit') { return; }
                el.addEventListener('input', function () {
                    if (searchTimer) { clearTimeout(searchTimer); }
                    searchTimer = setTimeout(function () {
                        autoFilterSubmit(form);
                    }, 700);
                });
                // Enter key submits natively; cancel a pending debounce first.
                el.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && searchTimer) {
                        clearTimeout(searchTimer);
                        searchTimer = null;
                    }
                });
            });

            form.addEventListener('submit', function () {
                form.classList.add('is-filtering');
                form.setAttribute('aria-busy', 'true');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAutoFilter);
    } else {
        bootAutoFilter();
    }
})();
</script>
