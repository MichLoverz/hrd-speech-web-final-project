{{-- Shared by create and edit. Expects $opening. --}}
@csrf

<x-field name="title" label="Judul lowongan">
    <input id="title" name="title" type="text" value="{{ old('title', $opening->title) }}" class="field-input" required maxlength="150" autofocus>
</x-field>

<x-field name="department" label="Departemen">
    <input id="department" name="department" type="text" value="{{ old('department', $opening->department) }}" class="field-input" maxlength="100" placeholder="Engineering, Operations, Marketing">
</x-field>

<x-field name="description" label="Deskripsi" hint="Ringkasan peran. Tidak ditampilkan ke kandidat.">
    <textarea id="description" name="description" rows="4" class="field-textarea" maxlength="5000">{{ old('description', $opening->description) }}</textarea>
</x-field>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
    <x-field name="salary_min" label="Gaji minimum (Rp)" hint="Dipakai untuk membandingkan ekspektasi kandidat, bukan untuk menilai kandidat.">
        <input id="salary_min" name="salary_min" type="number" min="0" step="100000" value="{{ old('salary_min', $opening->salary_min) }}" class="field-input">
    </x-field>
    <x-field name="salary_max" label="Gaji maksimum (Rp)">
        <input id="salary_max" name="salary_max" type="number" min="0" step="100000" value="{{ old('salary_max', $opening->salary_max) }}" class="field-input">
    </x-field>
</div>

<x-field name="status" label="Status">
    <select id="status" name="status" class="field-select">
        @foreach (App\Models\JobOpening::STATUSES as $s)
            <option value="{{ $s }}" @selected(old('status', $opening->status) === $s)>{{ status_label($s) }}</option>
        @endforeach
    </select>
</x-field>
