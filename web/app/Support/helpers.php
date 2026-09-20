<?php

if (! function_exists('rupiah')) {
    /** Format an integer as "Rp 7.000.000". Null becomes an en dash. */
    function rupiah(?int $amount): string
    {
        return $amount === null ? '–' : 'Rp '.number_format($amount, 0, ',', '.');
    }
}

if (! function_exists('status_label')) {
    /** Human-readable Indonesian label for any status enum value used in the app. */
    function status_label(?string $status): string
    {
        return match ($status) {
            // job openings
            'draft' => 'Draf',
            'open' => 'Dibuka',
            'closed' => 'Ditutup',
            // candidates
            'applied' => 'Melamar',
            'interviewing' => 'Tahap wawancara',
            'evaluated' => 'Sudah dinilai',
            'hired' => 'Diterima',
            'rejected' => 'Ditolak',
            // interview sessions
            'recording' => 'Sedang direkam',
            'transcribing' => 'Transkripsi',
            'analyzing' => 'Analisis',
            'ready_for_review' => 'Siap ditinjau',
            'approved' => 'Disetujui',
            // analysis results
            'edited' => 'Disunting',
            // users
            'admin' => 'Admin',
            'hrd' => 'HRD',
            'interviewer' => 'Pewawancara',
            default => (string) $status,
        };
    }
}

if (! function_exists('status_tone')) {
    /** Maps a status to a .status-* modifier class (see app.css). */
    function status_tone(?string $status): string
    {
        return match ($status) {
            'open', 'interviewing', 'recording', 'transcribing', 'analyzing' => 'status-active',
            'approved', 'hired', 'evaluated' => 'status-done',
            'ready_for_review', 'edited' => 'status-warn',
            default => '',
        };
    }
}
