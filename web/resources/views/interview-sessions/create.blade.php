<x-page title="Buat sesi wawancara" subtitle="Sesi dibuat sebagai draf. Perekaman dimulai dari halaman sesi setelah kandidat memberi persetujuan." :back="route('interview-sessions.index')" back-label="Semua sesi">
    <div class="sheet-section max-w-prose">
        @if ($candidates->isEmpty())
            <div class="empty">Belum ada kandidat. <a href="{{ route('candidates.create') }}">Tambahkan kandidat</a> dulu.</div>
        @else
            <form method="POST" action="{{ route('interview-sessions.store') }}">
                @csrf

                <x-field name="candidate_id" label="Kandidat">
                    <select id="candidate_id" name="candidate_id" class="field-select" required autofocus>
                        <option value="">Pilih kandidat</option>
                        @foreach ($candidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected((int) old('candidate_id', $selectedCandidateId) === $candidate->id)>
                                {{ $candidate->name }} ({{ $candidate->jobOpening->title }})
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="interviewer_id" label="Pewawancara utama" hint="Yang memegang kendali perekaman selama sesi.">
                    <select id="interviewer_id" name="interviewer_id" class="field-select" required>
                        @foreach ($interviewers as $user)
                            <option value="{{ $user->id }}" @selected((int) old('interviewer_id', $defaultInterviewerId) === $user->id)>
                                {{ $user->name }} ({{ status_label($user->role) }})
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <fieldset class="field">
                    <legend class="field-label">Panel tambahan</legend>
                    <div class="space-y-2 mt-1">
                        @foreach ($interviewers as $user)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="panel[]" value="{{ $user->id }}" class="field-checkbox" @checked(in_array($user->id, old('panel', [])))>
                                {{ $user->name }} <span class="text-pencil">({{ status_label($user->role) }})</span>
                            </label>
                        @endforeach
                    </div>
                    @error('panel')<span class="field-error">{{ $message }}</span>@enderror
                </fieldset>

                <x-field name="notes" label="Catatan sesi" hint="Opsional. Misalnya jadwal, ruangan, atau hal yang perlu ditanyakan lebih dalam.">
                    <textarea id="notes" name="notes" rows="3" class="field-textarea" maxlength="2000">{{ old('notes') }}</textarea>
                </x-field>

                <div class="field">
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="is_test_data" value="1" class="field-checkbox mt-0.5" @checked(old('is_test_data'))>
                        <span><span class="font-semibold">Sesi uji / demo</span>: naskah bacaan atau wawancara rekaan, bukan kandidat sungguhan. Tidak masuk angka evaluasi, dan boleh diproses LLM tier gratis. Tidak bisa diubah setelah sesi dibuat.</span>
                    </label>
                </div>

                <div class="mt-8 flex items-center gap-3">
                    <button type="submit" class="btn-primary">Buat sesi draf</button>
                    <a href="{{ route('interview-sessions.index') }}" class="text-sm">Batal</a>
                </div>
            </form>
        @endif
    </div>
</x-page>
