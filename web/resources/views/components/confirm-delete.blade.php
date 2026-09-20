@props(['action', 'label' => 'Hapus', 'confirm' => 'Hapus data ini? Tindakan ini tidak bisa dibatalkan.'])

<form method="POST" action="{{ $action }}" x-data @submit.prevent="if (confirm(@js($confirm))) $el.submit()" class="inline">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->merge(['class' => 'btn-danger']) }}>{{ $label }}</button>
</form>
