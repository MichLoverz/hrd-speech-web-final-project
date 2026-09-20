<x-guest-layout>
    <h1 class="mb-1">Buat akun</h1>
    <p class="text-sm text-pencil mb-5">Akun baru dibuat sebagai HRD. Admin bisa mengubah peran nanti.</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="field">
            <x-input-label for="name" value="Nama" />
            <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div class="field">
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div class="field">
            <x-input-label for="password" value="Kata sandi" />
            <x-text-input id="password" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="field">
            <x-input-label for="password_confirmation" value="Ulangi kata sandi" />
            <x-text-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" />
        </div>

        <div class="mt-6 flex items-center justify-between gap-4">
            <a class="text-sm" href="{{ route('login') }}">Sudah punya akun?</a>
            <x-primary-button>Buat akun</x-primary-button>
        </div>
    </form>
</x-guest-layout>
