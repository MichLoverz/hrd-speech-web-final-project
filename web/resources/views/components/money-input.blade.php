{{--
    Rupiah input that shows thousand separators while typing ("5.000.000")
    but submits a plain integer under {name}. The visible field has no name,
    so only the hidden input reaches the server.
--}}
@props(['name', 'value' => null, 'required' => false])

<div
    x-data="{
        raw: @js($value === null || $value === '' ? '' : (string) $value),
        shown: '',
        format(digits) {
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },
        onInput(e) {
            const digits = e.target.value.replace(/\D/g, '');
            this.raw = digits;
            this.shown = this.format(digits);
        },
        init() {
            this.shown = this.format(this.raw);
        },
    }"
    class="relative"
>
    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-pencil text-sm select-none" aria-hidden="true">Rp</span>
    <input
        id="{{ $name }}"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        x-model="shown"
        @input="onInput"
        @required($required)
        {{ $attributes->merge(['class' => 'field-input pl-10']) }}
    >
    <input type="hidden" name="{{ $name }}" :value="raw">
</div>
