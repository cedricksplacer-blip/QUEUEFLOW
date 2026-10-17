@php
    $darkMode = \App\Models\Setting::get('dark_mode', '0') === '1';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $darkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'QueueFlow TMC — Secure Access')</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @stack('styles')
    </head>
    <body class="qf-auth-body">
        <div class="qf-auth-shell">

            {{-- ============================ BRAND ASIDE ============================ --}}
            <aside class="qf-auth-aside" aria-hidden="true">
                <div class="qf-auth-lockup">
                    <span class="qf-auth-lockup-logo">
                        <img src="{{ asset('images/L.png') }}" alt="QueueFlow TMC">
                    </span>
                    <span>
                        <span class="qf-auth-lockup-name">QUEUEFLOW TMC</span>
                        <span class="qf-auth-lockup-sub">Trinidad Municipal College</span>
                    </span>
                </div>

                <div class="qf-auth-hero">
                    <h2>Smart campus <em>queue management</em>, built for TMC.</h2>
                    <p>
                        One secure console for administrators and staff to issue tickets,
                        call the next in line, and keep every office moving smoothly.
                    </p>

                    <ul class="qf-auth-points">
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="m9 12.75 2.25 2.25m0 0L15 9.75m-3.75 5.25L15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            Live queue boards for every service window
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/></svg>
                            Priority handling for PWD and senior citizens
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.745 3.745 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>
                            Role-based access with a fully audited trail
                        </li>
                    </ul>
                </div>

                <div class="qf-auth-aside-foot">
                    <span class="dot"></span>
                    Secure core system online
                </div>
            </aside>

            {{-- ============================ FORM PANEL ============================ --}}
            <main class="qf-auth-panel">
                <div class="qf-auth-panel-inner">

                    <div class="qf-auth-brand">
                        <span class="qf-auth-logo">
                            <img src="{{ asset('images/L.png') }}" alt="QueueFlow TMC">
                        </span>
                        <span class="qf-auth-title">QUEUEFLOW TMC</span>
                        <span class="qf-auth-subtitle">Smart Campus Queue Management</span>
                    </div>

                    <div class="qf-auth-card">
                        @yield('auth')
                    </div>

                </div>

                <p class="qf-auth-footnote">© {{ date('Y') }} QueueFlow TMC · Trinidad Municipal College</p>
            </main>

        </div>

        @stack('scripts')
    </body>
</html>
