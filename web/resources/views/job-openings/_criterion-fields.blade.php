{{-- Inline criterion fields. $criterion may be null (new). $prefix keeps ids unique; errors only show on the form that was submitted. --}}
@php $mine = old('_editor') === $prefix; @endphp

<div class="field">
    <label for="{{ $prefix }}-name" class="field-label">Nama kriteria</label>
    <input id="{{ $prefix }}-name" name="name" type="text" class="field-input" required maxlength="100"
           value="{{ $mine ? old('name') : $criterion?->name }}" placeholder="Pengalaman teknis, Komunikasi, Kerja tim">
    @if ($mine && $errors->has('name'))<span class="field-error">{{ $errors->first('name') }}</span>@endif
</div>

<div class="field">
    <label for="{{ $prefix }}-weight" class="field-label">Bobot (1–10)</label>
    <input id="{{ $prefix }}-weight" name="weight" type="number" min="1" max="10" class="field-input w-24" required
           value="{{ $mine ? old('weight') : ($criterion?->weight ?? 1) }}">
    @if ($mine && $errors->has('weight'))<span class="field-error">{{ $errors->first('weight') }}</span>@endif
</div>

<div class="field">
    <label for="{{ $prefix }}-description" class="field-label">Deskripsi</label>
    <textarea id="{{ $prefix }}-description" name="description" rows="2" class="field-textarea" maxlength="1000"
              placeholder="Apa yang dinilai lewat kriteria ini">{{ $mine ? old('description') : $criterion?->description }}</textarea>
    @if ($mine && $errors->has('description'))<span class="field-error">{{ $errors->first('description') }}</span>@endif
</div>
