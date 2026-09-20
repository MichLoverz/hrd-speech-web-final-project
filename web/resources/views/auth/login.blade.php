<x-guest-layout>
    <h1 class="mb-5">Masuk</h1>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div class="field">
            <x-input-label for="password" value="Kata sandi" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="field">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm">
                <input id="remember_me" type="checkbox" class="field-checkbox" name="remember">
                Tetap masuk di perangkat ini
            </label>
        </div>

        <div class="mt-6 flex items-center justify-between gap-4">
            @if (Route::has('password.request'))
                <a class="text-sm" href="{{ route('password.request') }}">Lupa kata sandi?</a>
            @endif
            <x-primary-button>Masuk</x-primary-button>
        </div>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 pt-5 border-t border-rule text-sm text-pencil">
            Belum punya akun? <a href="{{ route('register') }}">Daftar</a>
        </p>
    @endif
</x-guest-layout>
