<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

use App\Libraries\Kepegawaian\Struktur\StrukturService;

/**
 * MAKE-061 (H-08) — penyaji keluaran `services/s_stat`: format persis legacy [K] dan URL berkas v2 [V2]. Murni.
 *
 * - Tanggal "pengguna": `dateToUser()` legacy (`helpers/function_helper.php:972`) tanpa argumen format = `date("d M Y")`
 *   → "01 Apr 2026" (nama bulan Inggris singkat, locale C). Keputusan reviewer 05-11-2026: ikut kode legacy (bukan
 *   DD-MM-YYYY). `periode_kp` = `date("M Y")` ("Apr 2026"), `kp_bl` = `date("F")` ("April") (`L_employee.php:2666-2667`).
 * - `eselon` = `getEselon()` legacy (`function_helper.php:164`): sub group 1..4 → E.I..E.IV, selain itu ''.
 * - `nama` = `formatNamaGelar()` legacy → {@see StrukturService::namaGelar()}.
 * - URL berkas (keputusan Horii Q6-38, 05-11-2026): satu-satunya pembangun URL `arsip_*`/`file_*` — endpoint unduh klien
 *   layanan `services/s_stat/berkas/{jenis}/{nip}/{id}` (lampiran) dan `…/{id}/{kolom}` (berkas kolom), absolut dari
 *   `baseUrl`. Path internal tidak pernah keluar.
 */
final class PenyajiStat
{
    /** Jenis lampiran `document_attachment` yang boleh diunduh → kode `jenis_rwy` v2. */
    public const JENIS_LAMPIRAN = ['kp' => 11, 'skp' => 32, 'jabatan' => 9, 'kgb' => 10];

    /** Jenis berkas kolom yang boleh diunduh → [tabel, PK, daftar putih kolom] (nama kolom persis migration v2). */
    public const JENIS_KOLOM = [
        'pak'        => ['pegawai_ak', 'id_riwayat_ak', ['file_pak']],
        'tb'         => ['riwayat_tb', 'id_riwayat_tb', ['file_sp', 'file_1', 'file_2', 'file_3', 'file_surat_tb']],
        'ib'         => ['riwayat_ib', 'id_riwayat_ib', ['file_sp', 'file_1', 'file_2', 'file_surat_ib']],
        'karpeg'     => ['riwayat_karpeg', 'id_riwayat_karpeg', ['file_surat_hilang', 'file_1', 'file_2', 'file_3', 'file_4', 'file_5', 'file_tanda_terima']],
        'kariskarsu' => ['riwayat_kariskarsu', 'id_riwayat_kariskarsu', ['file_1', 'file_2', 'file_3', 'file_4', 'file_5', 'file_surat_hilang', 'file_alasan', 'file_tanda_terima']],
    ];

    public const ESELON = [1 => 'E.I', 2 => 'E.II', 3 => 'E.III', 4 => 'E.IV'];

    public function __construct(private readonly string $baseUrl)
    {
    }

    /** `dateToUser($tgl)` legacy: kosong → ''. */
    public static function tanggalPengguna(?string $ymd): string
    {
        return KalenderStat::valid($ymd) ? KalenderStat::parse((string) $ymd)->format('d M Y') : '';
    }

    /** `date("M Y", strtotime($tgl))` legacy. */
    public static function periodeKp(string $ymd): string
    {
        return KalenderStat::parse($ymd)->format('M Y');
    }

    /** `date("F", strtotime($tgl))` legacy. */
    public static function bulanKp(string $ymd): string
    {
        return KalenderStat::parse($ymd)->format('F');
    }

    public static function eselon(mixed $idSubGroupJabatan): string
    {
        return self::ESELON[(int) $idSubGroupJabatan] ?? '';
    }

    public static function namaGelar(mixed $glrAwal, mixed $nama, mixed $glrAkhir): string
    {
        return StrukturService::namaGelar($glrAwal === null ? null : (string) $glrAwal, (string) $nama, $glrAkhir === null ? null : (string) $glrAkhir);
    }

    /**
     * URL unduh lampiran `document_attachment` (jenis kp|skp|jabatan|kgb, id = id_attachment).
     */
    public function urlLampiran(string $jenis, string $nip, int|string $idAttachment): string
    {
        return $this->urlBerkas($jenis, $nip, $idAttachment, null);
    }

    /**
     * Satu-satunya pembangun URL berkas: `{baseUrl}/services/s_stat/berkas/{jenis}/{nip}/{id}[/{kolom}]`.
     */
    public function urlBerkas(string $jenis, string $nip, int|string $id, ?string $kolom = null): string
    {
        $url = rtrim($this->baseUrl, '/') . '/services/s_stat/berkas/' . rawurlencode($jenis) . '/' . rawurlencode($nip) . '/' . rawurlencode((string) $id);

        return $kolom === null ? $url : $url . '/' . rawurlencode($kolom);
    }

    /** Nama berkas unduhan generik `s_stat_{jenis}_{id}.{ext}` (tanpa path internal). */
    public static function namaUnduhan(string $jenis, int|string $id, string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1 ? $ext : 'bin';

        return "s_stat_{$jenis}_{$id}.{$ext}";
    }
}
