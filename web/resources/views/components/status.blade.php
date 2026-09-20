@props(['value'])

<span {{ $attributes->merge(['class' => 'status '.status_tone($value)]) }}>{{ status_label($value) }}</span>
