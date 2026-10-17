
<script>
window.QfLive = window.QfLive || (function () {
    function clockString() {
        try {
            return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function withParams(url, params) {
        if (!params) { return url; }
        var parts = [];
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value === null || value === undefined || value === '') { return; }
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
        });
        if (!parts.length) { return url; }
        return url + (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
    }

    function poll(options) {
        var url = options.url;
        var interval = options.interval || 30000;
        var failures = 0;
        var unhealthyAfter = options.unhealthyAfter || 2;
        var unhealthy = false;
        var busy = false;
        var timer = null;
        var stopped = false;

        function setUnhealthy(down) {
            if (down === unhealthy) { return; }
            unhealthy = down;
            if (typeof options.onHealthy === 'function') {
                try { options.onHealthy(!down); } catch (e) {}
            }
        }

        function pass() {
            if (stopped || busy) { return; }
            if (document.hidden) { return; }
            busy = true;
            fetch(withParams(url, options.params), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    busy = false;
                    if (data === null || data === undefined) {
                        failures++;
                    } else {
                        failures = 0;
                        setUnhealthy(false);
                        try { options.onData(data); } catch (e) {}
                    }
                    if (failures >= unhealthyAfter) { setUnhealthy(true); }
                })
                .catch(function () {
                    busy = false;
                    failures++;
                    if (failures >= unhealthyAfter) { setUnhealthy(true); }
                });
        }

        function onVisible() {
            if (!document.hidden && !stopped) { pass(); }
        }

        timer = setInterval(pass, interval);
        document.addEventListener('visibilitychange', onVisible);

        if (options.runNow !== false) {
            if (document.hidden) {
                // First pass waits for the tab; the visibility hook fires it.
            } else {
                setTimeout(pass, 0);
            }
        }

        function stop() {
            stopped = true;
            if (timer) { clearInterval(timer); timer = null; }
            document.removeEventListener('visibilitychange', onVisible);
        }

        window.addEventListener('pagehide', stop);

        return { refresh: pass, stop: stop, clockString: clockString };
    }

    return { poll: poll, clockString: clockString };
})();
</script>
<?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/partials/live-refresh.blade.php ENDPATH**/ ?>