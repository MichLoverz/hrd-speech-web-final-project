{{-- Shared by create and edit. Expects $candidate and $openings. --}}
@csrf

<x-field name="job_opening_id" label="Lowongan">
    <select id="job_opening_id" name="job_opening_id" class="field-select" required>
        <option value="">Pilih lowongan</option>
        @foreach ($openings as $opening)
            <option value="{{ $opening->id }}" @selected((int) old('job_opening_id', $candidate->job_opening_id) === $opening->id)>
                {{ $opening->title }}{{ $opening->status !== 'open' ? ' ('.status_label($opening->status).')' : '' }}
            </option>
        @endforeach
    </select>
</x-field>

<x-field name="name" label="Nama lengkap">
    <input id="name" name="name" type="text" value="{{ old('name', $candidate->name) }}" class="field-input" required maxlength="150">
</x-field>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
    <x-field name="email" label="Email">
        <input id="email" name="email" type="email" value="{{ old('email', $candidate->email) }}" class="field-input" maxlength="150">
    </x-field>
    <x-field name="phone" label="Telepon">
        <input id="phone" name="phone" type="tel" value="{{ old('phone', $candidate->phone) }}" class="field-input" maxlength="32">
    </x-field>
</div>

<x-field name="expected_salary" label="Ekspektasi gaji (Rp)" hint="Angka ini hanya dibandingkan dengan rentang anggaran lowongan.">
    <input id="expected_salary" name="expected_salary" type="number" min="0" step="100000" value="{{ old('expected_salary', $candidate->expected_salary) }}" class="field-input">
</x-field>

<x-field name="cv" label="Berkas CV" :hint="$candidate->cv_path ? 'Sudah ada CV tersimpan. Unggah berkas baru untuk menggantinya.' : 'PDF, maksimum 5 MB.'">
    <input id="cv" name="cv" type="file" accept="application/pdf" class="block w-full text-sm text-ink-soft">
</x-field>

<x-field name="status" label="Status">
    <select id="status" name="status" class="field-select">
        @foreach (App\Models\Candidate::STATUSES as $s)
            <option value="{{ $s }}" @selected(old('status', $candidate->status) === $s)>{{ status_label($s) }}</option>
        @endforeach
    </select>
</x-field>
