@extends('layouts.auth')

@section('title', 'QueueFlow TMC — Create New Password')

@section('auth')

    <div class="qf-auth-heading">
        <span class="eyebrow">Account Recovery</span>
        <h1>Create a new password</h1>
        <p>Your email is verified. Choose a strong new password.</p>
    </div>

    <div class="qf-auth-emailpill" style="margin-bottom:16px;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
        <span>{{ $email }}</span>
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
        action="{{ route('admin.password.update') }}"
        id="resetForm"
        class="qf-auth-form"
    >
        @csrf

        <div class="qf-auth-field">
            <label for="password" class="qf-label">New Password</label>
            <div class="qf-auth-pass">
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    minlength="6"
                    autocomplete="new-password"
                    placeholder="Enter new password"
                    class="input"
                >
                <button
                    type="button"
                    class="qf-auth-pass-toggle"
                    data-toggle="password"
                    data-eye="passwordEye"
                    aria-label="Show or hide password"
                >
                    <svg id="passwordEye" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                </button>
            </div>
            <p class="qf-help">Password must be at least <strong>6 characters</strong>.</p>
        </div>

        <div class="qf-auth-field">
            <label for="password_confirmation" class="qf-label">Confirm New Password</label>
            <div class="qf-auth-pass">
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    required
                    minlength="6"
                    autocomplete="new-password"
                    placeholder="Confirm new password"
                    class="input"
                >
                <button
                    type="button"
                    class="qf-auth-pass-toggle"
                    data-toggle="password_confirmation"
                    data-eye="confirmPasswordEye"
                    aria-label="Show or hide password"
                >
                    <svg id="confirmPasswordEye" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                </button>
            </div>
        </div>

        <div class="qf-auth-info">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 10V7a5 5 0 0 1 10 0v3"/><rect x="4" y="10" width="16" height="11" rx="2"/></svg>
            <span>
                Your password will be securely encrypted before it is saved to your
                <strong>administrator account</strong>.
            </span>
        </div>

        <div class="qf-auth-actions">
            <button type="submit" class="btn-primary qf-auth-submit" id="submitButton">
                <span id="buttonText">Create New Password</span>
                <span id="buttonArrow" aria-hidden="true">&rarr;</span>
            </button>
        </div>
    </form>

    <a href="{{ route('admin.login') }}" class="qf-auth-back">
        <span aria-hidden="true">&larr;</span>
        Back to administrator login
    </a>

    <div class="qf-auth-footer">
        End-to-end protected administrator session.
    </div>

@endsection

@push('scripts')
<script>
    (function () {
        const eyeOpen = '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18"/><path stroke-linecap="round" stroke-linejoin="round" d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.9 5.1A10.7 10.7 0 0 1 12 4.5c6 0 9.5 7.5 9.5 7.5a17.8 17.8 0 0 1-3.1 4.1"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.1 6.1C3.8 8.1 2.5 12 2.5 12s3.5 6 9.5 6c1.1 0 2.1-.2 3-.5"/>';
        const eyeClosed = '<path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>';

        document.querySelectorAll('.qf-auth-pass-toggle[data-toggle]').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const input = document.getElementById(toggle.dataset.toggle);
                const eye = document.getElementById(toggle.dataset.eye);
                if (!input || !eye) return;

                const hidden = input.type === 'password';
                input.type = hidden ? 'text' : 'password';
                eye.innerHTML = hidden ? eyeOpen : eyeClosed;
            });
        });

        const form = document.getElementById('resetForm');
        const buttonText = document.getElementById('buttonText');
        const buttonArrow = document.getElementById('buttonArrow');
        const button = document.getElementById('submitButton');

        if (form) {
            form.addEventListener('submit', function () {
                if (buttonText) buttonText.textContent = 'Securing...';
                if (buttonArrow) {
                    const spinner = document.createElement('span');
                    spinner.className = 'qf-auth-spinner';
                    buttonArrow.replaceWith(spinner);
                }
                if (button) button.disabled = true;
            });
        }
    })();
</script>
@endpush
