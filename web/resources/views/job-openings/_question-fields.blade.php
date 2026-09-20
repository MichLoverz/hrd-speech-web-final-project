{{-- Inline question fields. $question may be null (new). --}}
@php $mine = old('_editor') === $prefix; @endphp

<div class="field">
    <label for="{{ $prefix }}-text" class="field-label">Pertanyaan</label>
    <textarea id="{{ $prefix }}-text" name="text" rows="2" class="field-textarea" required maxlength="1000"
              placeholder="Tulis pertanyaan persis seperti yang akan dibacakan ke kandidat">{{ $mine ? old('text') : $question?->text }}</textarea>
    @if ($mine && $errors->has('text'))<span class="field-error">{{ $errors->first('text') }}</span>@endif
</div>

@if ($question)
    <div class="field">
        <label for="{{ $prefix }}-position" class="field-label">Urutan</label>
        <input id="{{ $prefix }}-position" name="position" type="number" min="1" class="field-input w-24"
               value="{{ $mine ? old('position') : $question->position }}">
        @if ($mine && $errors->has('position'))<span class="field-error">{{ $errors->first('position') }}</span>@endif
    </div>
@endif
