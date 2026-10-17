@extends('layouts.auth')

@section('title', 'QueueFlow TMC — Password Recovery')

@section('auth')

    <div class="qf-auth-heading">
        <span class="eyebrow">Account Recovery</span>
        <h1>Reset your password</h1>
        <p>We&rsquo;ll email you a 6-digit verification code.</p>
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
        action="{{ route('admin.password.email') }}"
        id="recoveryForm"
        class="qf-auth-form"
    >
        @csrf

        <p class="qf-auth-hint">
            Enter your administrator email address and we will send a
            <strong>6-digit verification code</strong> through Gmail. The code is
            valid for <strong>10 minutes</strong>.
        </p>

        <div class="qf-auth-field">
            <label for="email" class="qf-label">Administrator Email Address</label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                class="input"
                placeholder="Enter administrator email"
                autocomplete="email"
                autofocus
                required
            >
        </div>

        <div class="qf-auth-actions">
            <button type="submit" class="btn-primary qf-auth-submit" id="submitButton">
                <span id="buttonText">Send Verification Code</span>
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
        const form = document.getElementById('recoveryForm');
        const buttonText = document.getElementById('buttonText');
        const buttonArrow = document.getElementById('buttonArrow');
        const button = document.getElementById('submitButton');

        if (form) {
            form.addEventListener('submit', function () {
                if (buttonText) buttonText.textContent = 'Sending code...';
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
