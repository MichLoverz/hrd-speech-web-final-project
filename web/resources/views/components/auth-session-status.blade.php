@props(['status'])

@if ($status)
    <p {{ $attributes->merge(['class' => 'notice']) }} role="status">
        {{ $status }}
    </p>
@endif
