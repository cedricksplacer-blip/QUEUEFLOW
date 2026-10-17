<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Student ID (students) or Email (staff and admins) -->
        <div>
            <x-input-label for="identifier" value="Student ID or Email" />
            <x-text-input id="identifier" class="block mt-1 w-full" type="text" name="identifier" :value="old('identifier', old('email'))" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('identifier') ?? $errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

       <!-- ==========================================
     REMEMBER ME / FORGOT PASSWORD
=========================================== -->

<div class="flex items-center justify-between">

    <label class="flex cursor-pointer
                  items-center
                  gap-3
                  text-sm
                  text-slate-400">

        <input
            type="checkbox"
            name="remember"
            value="1"
            class="h-4
                   w-4
                   rounded
                   border-slate-700
                   bg-slate-950
                   text-blue-600
                   focus:ring-blue-500
                   focus:ring-offset-slate-900"
        >

        <span>
            Remember me
        </span>

    </label>


    <!-- FORGOT PASSWORD -->

    <a
        href="{{ route('admin.password.request') }}"
        class="text-sm
               font-semibold
               text-blue-400
               transition
               hover:text-blue-300"
    >

        Forgot password?

    </a>

</div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
