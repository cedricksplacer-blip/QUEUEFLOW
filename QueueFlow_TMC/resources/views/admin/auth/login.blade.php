@extends('layouts.auth')

@section('title', 'QueueFlow TMC — Administration Portal')

@section('auth')

    <div class="qf-auth-heading">
        <span class="eyebrow">STAFF &amp; ADMINISTRATOR AUTHENTICATION</span>
        <h1>Welcome back</h1>        <p>Sign in to the administrator &amp; staff console.</p>
    </div>

    @if (session('status'))
        <div class="alert qf-alert-success" role="status" style="margin-bottom:16px;">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert qf-alert-error" role="alert" style="margin-bottom:16px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('admin.login.store') }}"
        id="loginForm"
        class="qf-auth-form"
    >
        @csrf

        <div class="qf-auth-field">
            <label for="email" class="qf-label">QueueFlow Email</label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                class="input"
                placeholder="Enter your QueueFlow email"
                autocomplete="username"
                autofocus
                required
            >
        </div>

        <div class="qf-auth-field">
            <label for="password" class="qf-label">Security Password</label>
            <div class="qf-auth-pass">
                <input
                    id="password"
                    type="password"
                    name="password"
                    class="input"
                    placeholder="Enter secure password"
                    autocomplete="current-password"
                    required
                >
                <button
                    type="button"
                    class="qf-auth-pass-toggle"
                    id="passwordToggle"
                    aria-label="Show or hide password"
                >SHOW</button>
            </div>
        </div>

        <div class="qf-auth-meta">
            <label class="qf-auth-remember" for="remember">
                <input
                    type="checkbox"
                    name="remember"
                    id="remember"
                    value="1"
                    {{ old('remember') ? 'checked' : '' }}
                >
                Remember this device
            </label>

            <a href="{{ route('admin.password.request') }}" class="qf-auth-link">
                Forgot password?
            </a>
        </div>

        <div class="qf-auth-actions">
            <button type="submit" class="btn-primary qf-auth-submit" id="loginButton">
                <span id="buttonText">Access QueueFlow Portal</span>
                <span id="buttonArrow" aria-hidden="true">&rarr;</span>
            </button>
        </div>
    </form>

    <div class="qf-auth-footer">
        End-to-end protected staff &amp; administrator session.
    </div>

@endsection

@push('scripts')
<script>
    (function () {
        const password = document.getElementById('password');
        const toggle = document.getElementById('passwordToggle');

        if (password && toggle) {
            toggle.addEventListener('click', function () {
                const hidden = password.type === 'password';
                password.type = hidden ? 'text' : 'password';
                toggle.textContent = hidden ? 'HIDE' : 'SHOW';
            });
        }

        const remember = document.getElementById('remember');
        if (remember) {
            remember.addEventListener('change', function () {
                console.log('Remember this device: ' + (this.checked ? 'ON' : 'OFF'));
            });
        }

        const form = document.getElementById('loginForm');
        const buttonText = document.getElementById('buttonText');
        const buttonArrow = document.getElementById('buttonArrow');
        const button = document.getElementById('loginButton');

        if (form) {
            form.addEventListener('submit', function () {
                if (buttonText) buttonText.textContent = 'Authenticating...';
                if (buttonArrow) buttonArrow.style.display = 'none';
                if (button) button.disabled = true;
            });
        }
    })();
</script>
@endpush
