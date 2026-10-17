import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

// QueueFlow motion micro-interactions: no third-party animation library required.
document.addEventListener('click', (event) => {
    const target = event.target.closest('button, .btn, input[type="submit"], input[type="button"]');
    if (!target || target.disabled) return;
    const rect = target.getBoundingClientRect();
    const ripple = document.createElement('span');
    ripple.className = 'qf-ripple';
    ripple.style.left = `${event.clientX - rect.left}px`;
    ripple.style.top = `${event.clientY - rect.top}px`;
    target.appendChild(ripple);
    window.setTimeout(() => ripple.remove(), 520);
});


// =========================================================
// QueueFlow TMC — Animated dropdown indicators (native selects)
//
// Every native <select> ships with a static chevron painted in app.css as a
// no-JS fallback. Here we wrap each one in .qf-select-wrap and inject an SVG
// .qf-select-caret that rotates 180deg while the dropdown is open (CSS
// :focus-within) and eases back when it closes. The select keeps its id, name,
// value, event listeners and form membership, so nothing functional changes.
// =========================================================
function qfEnhanceSelects() {
    const SELECTOR = 'select:not([multiple]):not(.qf-has-caret):not([data-no-caret])';
    const CARET_PATH = 'M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z';

    document.querySelectorAll(SELECTOR).forEach((select) => {
        // Skip anything already inside a wrapper (e.g. rendered server-side).
        if (select.closest('.qf-select-wrap')) return;
        if (!select.parentNode) return;

        // Match the select's existing width behaviour: full-width for .input,
        // content-sized otherwise, and honour an explicit inline width so a
        // fixed-width select keeps its exact box and caret alignment.
        const explicitWidth = select.style.width;
        const fills = select.classList.contains('input') && !explicitWidth;

        const wrap = document.createElement('span');
        wrap.className = 'qf-select-wrap ' + (fills ? 'qf-select-wrap--fill' : 'qf-select-wrap--auto');
        if (explicitWidth) wrap.style.width = explicitWidth;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'qf-select-caret');
        svg.setAttribute('viewBox', '0 0 20 20');
        svg.setAttribute('fill', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('fill-rule', 'evenodd');
        path.setAttribute('clip-rule', 'evenodd');
        path.setAttribute('d', CARET_PATH);
        svg.appendChild(path);

        wrap.appendChild(svg);
        select.classList.add('qf-has-caret');
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', qfEnhanceSelects);
} else {
    qfEnhanceSelects();
}


// =========================================================
// QueueFlow TMC — Smooth dashboard motion engine
// =========================================================
document.addEventListener('DOMContentLoaded', () => {
    const dashboard = document.querySelector('.qf-dashboard');
    if (!dashboard) return;

    requestAnimationFrame(() => {
        requestAnimationFrame(() => dashboard.classList.add('qf-ready'));
    });

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Reveal cards only when they enter the viewport.
    const revealItems = dashboard.querySelectorAll('.qf-reveal');

    if ('IntersectionObserver' in window && !reduceMotion) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('qf-visible');
                obs.unobserve(entry.target);
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -30px 0px'
        });

        revealItems.forEach((item) => observer.observe(item));
    } else {
        revealItems.forEach((item) => item.classList.add('qf-visible'));
    }

    // Smooth number count-up using requestAnimationFrame.
    const counters = dashboard.querySelectorAll('.qf-count[data-target]');
    counters.forEach((counter, index) => {
        const target = Number(counter.dataset.target || 0);
        if (!Number.isFinite(target)) return;

        if (reduceMotion) {
            counter.textContent = Math.round(target).toLocaleString();
            return;
        }

        let start = 0;
        const duration = 850;
        const delay = Math.min(index * 45, 450);
        const startedAt = performance.now() + delay;

        const easeOut = (t) => 1 - Math.pow(1 - t, 3);

        const tick = (now) => {
            if (now < startedAt) {
                requestAnimationFrame(tick);
                return;
            }

            const progress = Math.min((now - startedAt) / duration, 1);
            const value = Math.round(start + (target - start) * easeOut(progress));
            counter.textContent = value.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                counter.classList.add('qf-counting');
                setTimeout(() => counter.classList.remove('qf-counting'), 260);
            }
        };

        requestAnimationFrame(tick);
    });

    // Very light pointer tilt for dashboard cards.
    // Disabled on touch devices and reduced-motion systems.
    if (!reduceMotion && window.matchMedia('(pointer: fine)').matches) {
        dashboard.querySelectorAll('.qf-tilt').forEach((card) => {
            let frame = null;

            card.addEventListener('pointermove', (event) => {
                const rect = card.getBoundingClientRect();
                const x = (event.clientX - rect.left) / rect.width - 0.5;
                const y = (event.clientY - rect.top) / rect.height - 0.5;

                if (frame) cancelAnimationFrame(frame);
                frame = requestAnimationFrame(() => {
                    card.style.setProperty('--qf-rx', `${(-y * 1.8).toFixed(2)}deg`);
                    card.style.setProperty('--qf-ry', `${(x * 1.8).toFixed(2)}deg`);
                    card.style.transform =
                        `translate3d(0, -4px, 0) scale(1.008) rotateX(var(--qf-rx)) rotateY(var(--qf-ry))`;
                });
            });

            card.addEventListener('pointerleave', () => {
                if (frame) cancelAnimationFrame(frame);
                card.style.transform = '';
            });
        });
    }
});
