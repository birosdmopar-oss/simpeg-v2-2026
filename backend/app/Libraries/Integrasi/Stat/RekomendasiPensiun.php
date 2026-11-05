<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

use App\Libraries\Kepegawaian\Kalkulasi\Pensiun;

/**
 * MAKE-061 (H-08, F6-09) — rekomendasi pensiun, port PERSIS [K] `libraries/hr/L_employee.php` `get_all_pensiun()`
 * :1324-1376 + `helpers/function_helper.php` `getTanggalPensiun()` :2400-2418. Murni: "hari ini" diinjeksi.
 *
 * - Populasi: pegawai status 1 dan `id_jenis_pegawai` = 1 (PNS saja), harus punya snapshot jabatan (disaring service).
 * - BUP (Q3): `jabatan.umur_pensiun` > 0 menang; selain itu 60 untuk struktural E.I/E.II (`id_group_jabatan` 1,
 *   `id_sub_group_jabatan` 1/2); selain itu 58 — sama persis dengan {@see Pensiun::batasUsia()} (dipakai ulang).
 * - Tanggal pensiun (Q2): `tanggal lahir + BUP tahun + 1 bulan` dengan `DateTime::modify` legacy lalu `Y-m-01`. Luapan
 *   bulan legacy DIPERTAHANKAN [K] (31-01-1968 + 58 th + 1 bln → 03-03-2026 → 2026-03-01), BERBEDA dari
 *   {@see Pensiun::tanggalPensiun()} v2 yang menjepit ke awal bulan (→ 2026-02-01). Perbedaan dicatat di README (PRD §7.6).
 * - `usia_th`/`usia_bl` = selisih tanggal lahir → hari ini (S_stat:768-775).
 */
final class RekomendasiPensiun
{
    public function __construct(private readonly string $hariIni)
    {
        KalenderStat::parse($hariIni);
    }

    public static function batasUsia(mixed $umurPensiun, mixed $idGroupJabatan, mixed $idSubGroupJabatan): int
    {
        return Pensiun::batasUsia(
            $umurPensiun === null || $umurPensiun === '' ? null : (int) $umurPensiun,
            $idGroupJabatan === null || $idGroupJabatan === '' ? null : (int) $idGroupJabatan,
            $idSubGroupJabatan === null || $idSubGroupJabatan === '' ? null : (int) $idSubGroupJabatan,
        );
    }

    /** `getTanggalPensiun()` legacy (luapan bulan dipertahankan). Tanggal lahir tidak valid → ''. */
    public static function tanggalPensiun(?string $tglLahir, int $batasUsia): string
    {
        if (! KalenderStat::valid((string) $tglLahir)) {
            return '';
        }

        return KalenderStat::parse((string) $tglLahir)
            ->modify("+{$batasUsia} year")
            ->modify('+1 month')
            ->format('Y-m-01');
    }

    /**
     * @return array{tgl_pensiun: string, pensiun_th: string, pensiun_bl: string}
     */
    public function hitung(?string $tglLahir, mixed $umurPensiun, mixed $idGroupJabatan, mixed $idSubGroupJabatan): array
    {
        $tgl = self::tanggalPensiun($tglLahir, self::batasUsia($umurPensiun, $idGroupJabatan, $idSubGroupJabatan));

        if ($tgl === '') {
            return ['tgl_pensiun' => '', 'pensiun_th' => '', 'pensiun_bl' => ''];
        }

        return [
            'tgl_pensiun' => $tgl,
            'pensiun_th'  => (string) KalenderStat::tahunLegacy($tgl),
            'pensiun_bl'  => (string) KalenderStat::bulanLegacy($tgl),
        ];
    }

    /**
     * Usia pada hari ini [K S_stat:768-775]; tanggal lahir tidak valid → ['', ''].
     *
     * @return array{0: int|string, 1: int|string}
     */
    public function usia(?string $tglLahir): array
    {
        if (! KalenderStat::valid((string) $tglLahir)) {
            return ['', ''];
        }

        $d = KalenderStat::parse((string) $tglLahir)->diff(KalenderStat::parse($this->hariIni));

        return [$d->y, $d->m];
    }
}
