@props(['name', 'label', 'hint' => null])

<div class="field">
    <label for="{{ $name }}" class="field-label">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <span class="field-hint">{{ $hint }}</span>
    @endif
    @error($name)
        <span class="field-error" id="{{ $name }}-error">{{ $message }}</span>
    @enderror
</div>
