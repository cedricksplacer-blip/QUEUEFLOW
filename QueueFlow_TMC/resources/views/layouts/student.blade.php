@php
    $darkMode = \App\Models\Setting::get('dark_mode', '0') === '1';
@endphp
<!DOCTYPE html>
<html lang="en" class="{{ $darkMode ? 'dark' : '' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Student') - QueueFlow TMC</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
<div class="min-h-screen">
    <main class="admin-main" style="width:100%;margin-left:0;">
        <header class="admin-header">
            <div class="header-inner">
                <div class="header-left">
                    <a href="{{ route('student.queues.index') }}" class="logo-box" style="text-decoration:none;">
                        <img src="{{ asset('images/L.png') }}" alt="Trinidad Municipal College seal" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;">
                    </a>
                    <div>
                        <div class="header-small-title">TMC Student Queue Desk</div>
                        <h1 class="header-title">@yield('title', 'My Queues')</h1>
                    </div>
                </div>
                <div class="header-right">
                    <a class="btn-secondary" href="{{ route('student.queues.index') }}">My Queues</a>
                    <a class="btn-primary" href="{{ route('student.queues.create') }}">Take a Ticket</a>
                    <a class="btn-secondary" href="{{ route('profile.edit') }}">Profile</a>
                    <div class="header-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                    <form method="POST" action="{{ route('admin.logout') }}">@csrf
                        <button type="submit" class="btn-danger">Logout</button>
                    </form>
                </div>
            </div>
        </header>
        <div class="admin-page student-page p-6 max-w-2xl mx-auto">
            @if(session('status'))
                <div class="admin-alert qf-alert-success">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="admin-alert qf-alert-error"><div style="font-weight:700;margin-bottom:4px;">Please correct the following:</div><ul style="list-style:disc;padding-left:18px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
