<x-page title="Akun saya" :subtitle="'Peran: '.status_label(auth()->user()->role)">
    <div class="sheet-section max-w-prose">
        @include('profile.partials.update-profile-information-form')
    </div>

    <div class="sheet-section max-w-prose">
        @include('profile.partials.update-password-form')
    </div>

    <div class="sheet-section max-w-prose">
        @include('profile.partials.delete-user-form')
    </div>
</x-page>
