<?php

/*
 * Subscription plans shown on /paket (Venture Creation, BMC revenue streams).
 *
 * DRAFT PRICES for discussion with the team — not market research and not
 * enforced anywhere yet. Quota counting, seats and payment come later; when
 * they do, they should read from here so the page and the limits never drift.
 */
return [
    'plans' => [
        'free' => [
            'name' => 'Uji coba',
            'price' => 0,
            'period' => null,
            'tagline' => 'Coba HireNote pada wawancara sungguhan.',
            'sessions' => 5,
            'sessions_note' => 'sesi, sekali pakai',
            'max_minutes' => 30,
            'seats' => 2,
            'retention_days' => 30,
        ],
        'quota' => [
            'name' => 'Paket kuota',
            'price' => 149_000,
            'period' => 'per 10 sesi',
            'tagline' => 'Untuk perusahaan yang merekrut sesekali.',
            'sessions' => 10,
            'sessions_note' => 'sesi, berlaku 6 bulan',
            'max_minutes' => 60,
            'seats' => 3,
            'retention_days' => 90,
        ],
        'monthly' => [
            'name' => 'Bulanan',
            'price' => 399_000,
            'period' => 'per bulan',
            'tagline' => 'Untuk tim yang merekrut setiap bulan.',
            'sessions' => 40,
            'sessions_note' => 'sesi per bulan',
            'max_minutes' => 90,
            'seats' => 5,
            'retention_days' => 180,
            'featured' => true,
        ],
        'yearly' => [
            'name' => 'Tahunan',
            'price' => 3_990_000,
            'period' => 'per tahun',
            'tagline' => 'Sama dengan Bulanan, bayar 10 bulan untuk 12.',
            'sessions' => 40,
            'sessions_note' => 'sesi per bulan',
            'max_minutes' => 90,
            'seats' => 5,
            'retention_days' => 365,
        ],
    ],

    // Included in every plan, free trial too: the trial must show the real product.
    'included' => [
        'Transkrip real-time bahasa Indonesia, termasuk istilah Inggris',
        'Pemisahan suara pewawancara dan kandidat',
        'Kartu skor AI dengan kutipan yang dicek ke transkrip',
        'Penilaian buta antar-pewawancara dan halaman pembanding',
        'Koreksi transkrip dan rekam ulang oleh HRD',
    ],

    'addons' => [
        ['name' => 'Tambahan sesi', 'price' => 12_000, 'unit' => 'per sesi', 'note' => 'Untuk paket Bulanan/Tahunan yang kuotanya habis.'],
        ['name' => 'Kursi pewawancara', 'price' => 49_000, 'unit' => 'per akun per bulan', 'note' => 'Untuk user atau hiring manager yang ikut mewawancarai.'],
        ['name' => 'Kosakata khusus industri', 'price' => 99_000, 'unit' => 'per bulan', 'note' => 'Daftar istilah per lowongan supaya transkrip lebih tepat.'],
        ['name' => 'Template kriteria dan pertanyaan', 'price' => 149_000, 'unit' => 'per paket jabatan', 'note' => 'Kriteria dan pertanyaan siap pakai untuk satu bidang.'],
        ['name' => 'Retensi rekaman 1 tahun + ekspor arsip', 'price' => 79_000, 'unit' => 'per bulan', 'note' => 'Untuk paket selain Tahunan.'],
    ],
];
