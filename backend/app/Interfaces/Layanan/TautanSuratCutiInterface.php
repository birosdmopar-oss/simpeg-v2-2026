<?php

declare(strict_types=1);

namespace App\Interfaces\Layanan;

/**
 * Tautan publik surat cuti ber-token (keputusan Horii 03-11-2026 F6-18 K3b/K3c): satu token acak per surat dipakai URL
 * PDF ber-TTE (isi QR `imageTTD` tanda tangan BSrE, ikut legacy) dan URL halaman verifikasi (QR kaki surat). Token tidak
 * memuat NIP dan tidak pernah dicatat. Implementasi menyimpan token di tabel DBV-030 — sampai skema itu di `main`,
 * pemanggil menerima null dan surat ditandatangani tanpa visualisasi QR (`image=false`).
 */
interface TautanSuratCutiInterface
{
    /**
     * URL publik PDF surat cuti ber-TTE (dipasang di QR tanda tangan SEBELUM ditandatangani); null bila belum tersedia.
     */
    public function urlPdfTte(int $idCuti): ?string;
}
