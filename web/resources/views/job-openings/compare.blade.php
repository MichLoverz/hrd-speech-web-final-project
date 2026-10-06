@php
    $positionLabel = [
        'below_range' => 'di bawah anggaran',
        'within_range' => 'dalam anggaran',
        'above_range' => 'di atas anggaran',
    ];
@endphp

<x-page :title="'Perbandingan kandidat: '.$opening->title" subtitle="Skor rata-rata dari penilaian manual yang sudah dikirim. Ekspektasi gaji hanya dibandingkan dengan anggaran, bukan dinilai." :back="route('job-openings.show', $opening)" back-label="Kembali ke lowongan">
    <div class="sheet-section">
        @if ($rows->isEmpty())
            <div class="empty">Belum ada kandidat untuk lowongan ini.</div>
        @elseif ($opening->criteria->isEmpty())
            <div class="empty">Lowongan ini belum punya kriteria, jadi belum ada yang bisa dibandingkan.</div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Kandidat</th>
                            @foreach ($opening->criteria as $criterion)
                                <th class="text-right whitespace-nowrap">{{ $criterion->name }} <span class="font-normal text-pencil">×{{ $criterion->weight }}</span></th>
                            @endforeach
                            <th class="text-right">Total</th>
                            <th class="text-right">Penilai</th>
                            <th>Ekspektasi gaji</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><a href="{{ route('candidates.show', $row['candidate']) }}" class="font-semibold">{{ $row['candidate']->name }}</a></td>
                                @foreach ($opening->criteria as $criterion)
                                    <td class="text-right tabular-nums">{{ \App\Support\ScoreScale::format($row['per_criterion'][$criterion->id]) }}</td>
                                @endforeach
                                <td class="text-right tabular-nums font-semibold">{{ \App\Support\ScoreScale::format($row['weighted']) }}</td>
                                <td class="text-right tabular-nums">{{ $row['raters'] }}</td>
                                <td class="whitespace-nowrap">
                                    {{ rupiah($row['candidate']->expected_salary) }}
                                    @if ($row['salary_position'])
                                        <span class="block text-sm {{ $row['salary_position'] === 'within_range' ? 'text-pencil' : 'text-ink' }}">{{ $positionLabel[$row['salary_position']] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap"><x-status :value="$row['candidate']->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-sm text-pencil max-w-prose">Total = rata-rata skor per kriteria dikalikan bobotnya, dibagi jumlah bobot. Anggaran lowongan: {{ rupiah($opening->salary_min) }} – {{ rupiah($opening->salary_max) }}.</p>
        @endif
    </div>
</x-page>
