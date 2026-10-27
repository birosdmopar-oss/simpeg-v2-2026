<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

/**
 * Berkas pengajuan TB/IB di `document_attachment` (PRD Fase 4 §6.7.2, §6.8.1, §7.4; DQ-20; keputusan reviewer CR
 * 27-10-2026 butir 3). Murni.
 *
 * [V2] Satu kode `jenis_rwy` per jenis (46 TB, 49 IB) menampung beberapa berkas; pembeda slot = kolom
 * `document_attachment.tag` berisi nama kolom legacy (`file_sp`, `file_1`, `file_2`, `file_3`) — pola Arsip Data Umum
 * (`LampiranController`, Fase 3). Kolom `file_*` di `riwayat_tb`/`riwayat_ib` tidak diisi v2 (tetap NULL); nilai dari
 * data impor dianggap slot sudah berisi. Kode satu-slot (47 SK TB, 48 perpanjangan TB, 50 surat IB) tanpa `tag`.
 */
final class BerkasStudi
{
    /** Kode `jenis_rwy` (seed DBV-022 `SeedJenisRwyLayananFase4`). */
    public const KODE_TB = 46;

    public const KODE_SURAT_TB = 47;

    public const KODE_TB_PERPAN = 48;

    public const KODE_IB = 49;

    public const KODE_SURAT_IB = 50;

    /** Batas ukuran (MB) dan whitelist ekstensi TB/IB — satu whitelist tambah & ubah [V2 TB-05, Q-15]. */
    public const BATAS_MB = 5;

    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];

    /** SK TB unggahan PIC (`file_surat_tb`) dan berkas perpanjangan: PDF 5 MB [K]. */
    public const EKSTENSI_SK = ['pdf'];

    /**
     * Slot berkas pengajuan per jenis: nama kolom legacy => label [K COMMENT D1 / form legacy].
     */
    public const SLOT = [
        AturanStudi::JENIS_TB => [
            'file_sp' => 'Surat Pengantar',
            'file_1'  => 'Surat Keterangan Kampus',
            'file_2'  => 'Surat Keterangan BAN PT',
            'file_3'  => 'Surat Pernyataan Bermaterai',
        ],
        AturanStudi::JENIS_IB => [
            'file_sp' => 'Surat Pengantar',
            'file_1'  => 'Surat Keterangan Kampus',
            'file_2'  => 'Surat Keterangan BAN PT',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, string> slot => label
     */
    public static function slot(string $jenis): array
    {
        return self::SLOT[$jenis] ?? throw new \InvalidArgumentException("Jenis studi {$jenis} tidak dikenal.");
    }

    public static function kodeLampiran(string $jenis): int
    {
        return $jenis === AturanStudi::JENIS_TB ? self::KODE_TB : self::KODE_IB;
    }

    public static function kodeSurat(string $jenis): int
    {
        return $jenis === AturanStudi::JENIS_TB ? self::KODE_SURAT_TB : self::KODE_SURAT_IB;
    }

    /**
     * Galat per slot yang masih kosong: slot dianggap berisi bila ada berkas baru, ada lampiran v2 ber-`tag` slot itu,
     * atau kolom legacy `file_*` (data impor) terisi.
     *
     * @param list<string>             $berkasBaru   slot yang diunggah pada permintaan ini
     * @param list<string>             $tagTersimpan `tag` lampiran v2 yang sudah ada untuk baris ini
     * @param array<string, mixed>     $baris        baris `riwayat_tb`/`riwayat_ib` (kosong saat tambah)
     *
     * @return array<string, string> slot => pesan
     */
    public static function slotKosong(string $jenis, array $berkasBaru, array $tagTersimpan, array $baris = []): array
    {
        $errors = [];

        foreach (self::slot($jenis) as $slot => $label) {
            $legacy = trim((string) ($baris[$slot] ?? ''));

            if (in_array($slot, $berkasBaru, true) || in_array($slot, $tagTersimpan, true) || $legacy !== '') {
                continue;
            }

            $errors[$slot] = "{$label} wajib diunggah.";
        }

        return $errors;
    }
}
