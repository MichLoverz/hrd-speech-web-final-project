{{-- Plans page (Venture Creation). Display only: prices are a draft and nothing is charged or enforced yet. --}}
@php
    $plans = config('plans.plans');
    $perMonth = fn (array $p) => $p['period'] === 'per tahun' ? intdiv($p['price'], 12) : null;
@endphp

<x-page title="Paket langganan" subtitle="Satu harga per perusahaan, bukan per kandidat. Semua paket memakai mesin transkripsi dan kartu skor yang sama.">
    <div class="sheet-section">
        <p class="notice mb-8"><span class="font-semibold">Rancangan harga untuk prototype.</span> Angka di halaman ini masih draf untuk didiskusikan, dan belum ada pembayaran yang diproses.</p>

        <div class="grid gap-y-10 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($plans as $key => $plan)
                <section class="plan {{ ! empty($plan['featured']) ? 'plan-featured' : '' }}" x-data="{ picked: false }">
                    <div class="min-h-[1.5rem]">
                        @if (! empty($plan['featured']))
                            <span class="highlight text-xs font-semibold uppercase tracking-wider">Paling sering dipilih</span>
                        @endif
                    </div>
                    <h2 class="mt-1">{{ $plan['name'] }}</h2>
                    <p class="text-sm text-ink-soft mt-1 min-h-[2.5rem]">{{ $plan['tagline'] }}</p>

                    <p class="mt-4 tabular-nums md:min-h-[5.5rem]">
                        <span class="text-2xl font-semibold">{{ $plan['price'] === 0 ? 'Gratis' : rupiah($plan['price']) }}</span>
                        @if ($plan['period'])
                            <span class="block text-sm text-pencil">{{ $plan['period'] }}</span>
                        @endif
                        @if ($perMonth($plan))
                            <span class="block text-sm text-pencil">setara {{ rupiah($perMonth($plan)) }} per bulan</span>
                        @endif
                    </p>

                    <dl class="plan-facts mt-5 mb-6">
                        <div><dt>Kuota</dt><dd>{{ $plan['sessions'] }} {{ $plan['sessions_note'] }}</dd></div>
                        <div><dt>Durasi</dt><dd>maks {{ $plan['max_minutes'] }} menit per sesi</dd></div>
                        <div><dt>Pewawancara</dt><dd>{{ $plan['seats'] }} akun</dd></div>
                        <div><dt>Simpan rekaman</dt><dd>{{ $plan['retention_days'] }} hari</dd></div>
                    </dl>

                    <button type="button" class="{{ ! empty($plan['featured']) ? 'btn-primary' : 'btn-quiet' }} w-full" @click="picked = true">
                        {{ $plan['price'] === 0 ? 'Mulai uji coba' : 'Pilih paket' }}
                    </button>
                    <p x-show="picked" x-cloak class="text-xs text-pencil mt-2" role="status">Prototype: pendaftaran dan pembayaran belum tersedia.</p>
                </section>
            @endforeach
        </div>
    </div>

    <div class="sheet-section">
        <h2>Ada di semua paket</h2>
        <ul class="list-disc pl-5 mt-3 space-y-1 max-w-prose">
            @foreach (config('plans.included') as $feature)
                <li>{{ $feature }}</li>
            @endforeach
        </ul>
    </div>

    <div class="sheet-section">
        <h2>Tambahan</h2>
        <table class="ledger mt-4">
            <thead>
                <tr><th>Tambahan</th><th class="hidden sm:table-cell">Keterangan</th><th class="text-right">Harga</th></tr>
            </thead>
            <tbody>
                @foreach (config('plans.addons') as $addon)
                    <tr>
                        <td class="font-semibold">{{ $addon['name'] }}<span class="block sm:hidden text-sm font-normal text-ink-soft">{{ $addon['note'] }}</span></td>
                        <td class="hidden sm:table-cell text-sm text-ink-soft">{{ $addon['note'] }}</td>
                        <td class="text-right tabular-nums whitespace-nowrap">{{ rupiah($addon['price']) }}<span class="block text-sm text-pencil">{{ $addon['unit'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sheet-section">
        <h2>Cara kuota dihitung</h2>
        <ul class="list-disc pl-5 mt-3 space-y-1 max-w-prose text-sm">
            <li>Satu sesi = satu wawancara dengan satu kandidat, sampai batas durasi paketnya.</li>
            <li>Durasi dihitung dari audio yang benar-benar diproses server, bukan dari jam di browser.</li>
            <li>Rekam ulang tetap memakai menit dari kuota. Membagi satu wawancara menjadi beberapa sesi memakai beberapa sesi.</li>
            <li>Wawancara tahap kedua dengan kandidat yang sama dihitung sebagai sesi baru.</li>
        </ul>
    </div>
</x-page>
