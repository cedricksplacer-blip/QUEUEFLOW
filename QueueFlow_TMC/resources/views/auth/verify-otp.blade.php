@extends('layouts.auth')

@section('title', 'QueueFlow TMC — Code Verification')

@section('auth')

    <div class="qf-auth-heading">
        <span class="eyebrow">Two-Step Verification</span>
        <h1>Enter your code</h1>
        <p>We sent a 6-digit verification code to your email.</p>
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
        action="{{ route('admin.password.verify') }}"
        id="otpForm"
        class="qf-auth-form"
    >
        @csrf

        <div class="qf-auth-field qf-auth-otp">
            <label for="otp" class="qf-label">Verification Code</label>
            <input
                id="otp"
                type="text"
                name="otp"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                minlength="6"
                required
                autofocus
                autocomplete="one-time-code"
                placeholder="000000"
                class="input"
            >
        </div>

        <div class="qf-auth-info">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 10V7a5 5 0 0 1 10 0v3"/><rect x="4" y="10" width="16" height="11" rx="2"/></svg>
            <span>
                Your verification code is valid for <strong>10 minutes</strong>.
                Do not share this code with anyone.
            </span>
        </div>

        <div class="qf-auth-actions">
            <button type="submit" class="btn-primary qf-auth-submit" id="submitButton">
                <span id="buttonText">Verify Code</span>
                <span id="buttonArrow" aria-hidden="true">&rarr;</span>
            </button>
        </div>
    </form>

    <div class="qf-auth-inline-actions">
        <span>Didn&rsquo;t receive the code?</span>
        <form method="POST" action="{{ route('admin.password.resend') }}">
            @csrf
            <button type="submit" class="qf-auth-link">Resend code</button>
        </form>
    </div>

    <div class="qf-auth-inline-actions" style="margin-top:6px;">
        <a href="{{ route('admin.password.request') }}" class="qf-auth-back" style="margin-top:0;">
            <span aria-hidden="true">&larr;</span>
            Use a different email
        </a>
    </div>

    <div class="qf-auth-footer">
        End-to-end protected administrator session.
    </div>

@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('otpForm');
        const buttonText = document.getElementById('buttonText');
        const buttonArrow = document.getElementById('buttonArrow');
        const button = document.getElementById('submitButton');

        if (form) {
            form.addEventListener('submit', function () {
                if (buttonText) buttonText.textContent = 'Verifying...';
                if (buttonArrow) {
                    const spinner = document.createElement('span');
                    spinner.className = 'qf-auth-spinner';
                    buttonArrow.replaceWith(spinner);
                }
                if (button) button.disabled = true;
            });
        }

        const otp = document.getElementById('otp');
        if (otp) {
            otp.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        }
    })();
</script>
@endpush
