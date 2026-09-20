@php
    $canEdit = auth()->user()->can('update', $opening);
    // Which inline editor (if any) should reopen after a validation error.
    $reopen = old('_editor');
@endphp

<x-page :title="$opening->title" :subtitle="trim(($opening->department ? $opening->department.' — ' : '').'dibuat oleh '.$opening->creator->name)" :back="route('job-openings.index')" back-label="Semua lowongan">
    <x-slot:actions>
        <x-status :value="$opening->status" class="mr-2" />
        @if ($canEdit)
            <a href="{{ route('job-openings.edit', $opening) }}" class="btn-quiet">Ubah lowongan</a>
        @endif
    </x-slot:actions>

    <div class="sheet-section grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-2 max-w-prose">
            @if ($opening->description)
                <p class="whitespace-pre-line">{{ $opening->description }}</p>
            @else
                <p class="text-pencil">Belum ada deskripsi.</p>
            @endif
        </div>
        <dl class="text-sm space-y-3">
            <div>
                <dt class="text-pencil">Rentang gaji</dt>
                <dd>{{ rupiah($opening->salary_min) }} – {{ rupiah($opening->salary_max) }}</dd>
            </div>
            <div>
                <dt class="text-pencil">Kandidat</dt>
                <dd>{{ $opening->candidates->count() }} orang</dd>
            </div>
            <div>
                <dt class="text-pencil">Pertanyaan</dt>
                <dd>{{ $opening->criteria->sum(fn ($c) => $c->questions->count()) }} pertanyaan dalam {{ $opening->criteria->count() }} kriteria</dd>
            </div>
        </dl>
    </div>

    {{-- Criteria and their questions --}}
    <div class="sheet-section" x-data="{ editor: @js($reopen) }">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
            <h2>Kriteria penilaian</h2>
            <p class="text-sm text-pencil">Bobot menentukan porsi kriteria pada nilai akhir. Tiap pertanyaan terikat ke satu kriteria.</p>
        </div>

        @if ($opening->criteria->isEmpty())
            <div class="empty">Belum ada kriteria. Tambahkan minimal satu kriteria supaya bisa membuat pertanyaan.</div>
        @endif

        @foreach ($opening->criteria as $criterion)
            @php $ceid = 'c'.$criterion->id; @endphp
            <section class="py-5 border-t border-rule first:border-t-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="flex items-baseline gap-3">
                            {{ $criterion->name }}
                            <span class="text-sm font-normal text-pencil">bobot {{ $criterion->weight }}</span>
                        </h3>
                        @if ($criterion->description)
                            <p class="mt-1 text-sm text-ink-soft max-w-prose">{{ $criterion->description }}</p>
                        @endif
                    </div>
                    @if ($canEdit)
                        <div class="flex items-center gap-3 text-sm">
                            <button type="button" class="row-action" @click="editor = editor === '{{ $ceid }}' ? null : '{{ $ceid }}'">Ubah</button>
                            <x-confirm-delete :action="route('criteria.destroy', [$opening, $criterion])" label="Hapus" class="row-action-danger" confirm="Hapus kriteria ini beserta semua pertanyaannya?" />
                        </div>
                    @endif
                </div>

                @if ($canEdit)
                    <form method="POST" action="{{ route('criteria.update', [$opening, $criterion]) }}" x-show="editor === '{{ $ceid }}'" x-cloak class="mt-4 max-w-prose border-l-2 border-ink pl-4">
                        @csrf @method('PUT')
                        <input type="hidden" name="_editor" value="{{ $ceid }}">
                        @include('job-openings._criterion-fields', ['criterion' => $criterion, 'prefix' => $ceid])
                        <div class="mt-4 flex items-center gap-3">
                            <button type="submit" class="btn-primary">Simpan kriteria</button>
                            <button type="button" class="text-sm underline" @click="editor = null">Batal</button>
                        </div>
                    </form>
                @endif

                <ol class="mt-4 space-y-2 list-decimal pl-6 max-w-prose">
                    @forelse ($criterion->questions as $question)
                        @php $qeid = 'q'.$question->id; @endphp
                        <li class="pl-1">
                            <div class="flex items-start justify-between gap-3">
                                <span>{{ $question->text }}</span>
                                @if ($canEdit)
                                    <span class="flex items-center gap-3 text-sm whitespace-nowrap">
                                        <button type="button" class="row-action" @click="editor = editor === '{{ $qeid }}' ? null : '{{ $qeid }}'">Ubah</button>
                                        <x-confirm-delete :action="route('questions.destroy', [$criterion, $question])" label="Hapus" class="row-action-danger" confirm="Hapus pertanyaan ini?" />
                                    </span>
                                @endif
                            </div>
                            @if ($canEdit)
                                <form method="POST" action="{{ route('questions.update', [$criterion, $question]) }}" x-show="editor === '{{ $qeid }}'" x-cloak class="mt-2 border-l-2 border-ink pl-4">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="_editor" value="{{ $qeid }}">
                                    @include('job-openings._question-fields', ['question' => $question, 'prefix' => $qeid])
                                    <div class="mt-3 flex items-center gap-3">
                                        <button type="submit" class="btn-primary">Simpan pertanyaan</button>
                                        <button type="button" class="text-sm underline" @click="editor = null">Batal</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="list-none -ml-6 text-sm text-pencil">Belum ada pertanyaan untuk kriteria ini.</li>
                    @endforelse
                </ol>

                @if ($canEdit)
                    @php $nqid = 'nq'.$criterion->id; @endphp
                    <div class="mt-3 pl-6">
                        <button type="button" class="row-action" x-show="editor !== '{{ $nqid }}'" @click="editor = '{{ $nqid }}'">Tambah pertanyaan</button>
                        <form method="POST" action="{{ route('questions.store', $criterion) }}" x-show="editor === '{{ $nqid }}'" x-cloak class="max-w-prose border-l-2 border-ink pl-4">
                            @csrf
                            <input type="hidden" name="_editor" value="{{ $nqid }}">
                            @include('job-openings._question-fields', ['question' => null, 'prefix' => $nqid])
                            <div class="mt-3 flex items-center gap-3">
                                <button type="submit" class="btn-primary">Tambah pertanyaan</button>
                                <button type="button" class="text-sm underline" @click="editor = null">Batal</button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>
        @endforeach

        @if ($canEdit)
            <div class="pt-5 border-t border-rule">
                <button type="button" class="btn-quiet" x-show="editor !== 'nc'" @click="editor = 'nc'">Tambah kriteria</button>
                <form method="POST" action="{{ route('criteria.store', $opening) }}" x-show="editor === 'nc'" x-cloak class="max-w-prose border-l-2 border-ink pl-4">
                    @csrf
                    <input type="hidden" name="_editor" value="nc">
                    @include('job-openings._criterion-fields', ['criterion' => null, 'prefix' => 'nc'])
                    <div class="mt-4 flex items-center gap-3">
                        <button type="submit" class="btn-primary">Tambah kriteria</button>
                        <button type="button" class="text-sm underline" @click="editor = null">Batal</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    {{-- Candidates --}}
    <div class="sheet-section">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-4">
            <h2>Kandidat</h2>
            @can('create', App\Models\Candidate::class)
                @if ($canEdit)
                    <a href="{{ route('candidates.create', ['job_opening_id' => $opening->id]) }}" class="btn-quiet">Tambah kandidat</a>
                @endif
            @endcan
        </div>

        @if ($opening->candidates->isEmpty())
            <div class="empty">Belum ada kandidat untuk lowongan ini.</div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Ekspektasi gaji</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opening->candidates as $candidate)
                            <tr>
                                <td><a href="{{ route('candidates.show', $candidate) }}" class="font-semibold">{{ $candidate->name }}</a></td>
                                <td class="whitespace-nowrap">{{ rupiah($candidate->expected_salary) }}</td>
                                <td><x-status :value="$candidate->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-page>
