<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Rekap;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Efek penyesuaian Tukin & susunan keterangan baris laporan/rekap (PRD 5B §7.3.3, §7.3.10, F5B-14) — **murni**. Setelah
 * `KalkulasiTukin::hitung()`; `total_potongan` **tidak** pernah diubah (legacy pun hanya menampilkan teks,
 * `L_presensi.php:13064-13120`).
 *
 * - {@see self::aktif()}: baris `penyesuaian_tukin` status 1 yang mencakup bulan laporan (status 2/10 diabaikan; CRUD
 *   MAKE-045 menjamin tidak tumpang tindih — bila tetap ada, id terbesar).
 * - {@see self::keterangan()} [K urutan `:12660`, `:12772`, `:13064-13125`]: dasar = "(Tugas Belajar) <institusi>" bila ada
 *   hari TB, atau "Cuti di Luar Tanggungan Negara" bila `id_jenis_status = 3`; diawali "Penyesuaian Tukin <alasan>
 *   <persen>% (sebelum potongan lain)." [V2 teks dari tabel, pengganti teks hard-code]; lalu daftar cuti
 *   "<jenis> (<tgl> s.d <tgl>)" per baris. Pemisah baris "\n" (legacy `<br/>`; XLSX legacy mengganti ke "\n") tanpa
 *   pemisah di awal [V2].
 * - {@see self::akhiranTotal()}: akhiran total PDF per pegawai "<total>%<akhiran>" [K `print/laporan_tukin_skp_pdf.php:254`]:
 *   persen negatif −N → " + N%" (pengurangan tambahan, bentuk legacy), positif N → " - N%".
 */
final class PenyesuaianTukin
{
    public const CLTN = 'Cuti di Luar Tanggungan Negara';

    private function __construct()
    {
    }

    /**
     * @param list<array{id_penyesuaian_tukin?: int|string, bulan_mulai: string, bulan_akhir: string, persen: int|float|string, alasan: string, status: int|string}> $baris
     *
     * @return array{persen: string, alasan: string}|null
     */
    public static function aktif(array $baris, string $bulanTukin): ?array
    {
        $bulan = substr($bulanTukin, 0, 7) . '-01';
        $cocok = array_values(array_filter($baris, static fn (array $b): bool => (int) $b['status'] === 1
            && substr((string) $b['bulan_mulai'], 0, 10) <= $bulan && substr((string) $b['bulan_akhir'], 0, 10) >= $bulan));

        usort($cocok, static fn (array $a, array $b): int => (int) ($b['id_penyesuaian_tukin'] ?? 0) <=> (int) ($a['id_penyesuaian_tukin'] ?? 0));

        return $cocok === [] ? null : ['persen' => self::persen($cocok[0]['persen']), 'alasan' => trim((string) $cocok[0]['alasan'])];
    }

    /**
     * @param array{persen: string, alasan: string}|null           $penyesuaian
     * @param list<array{0: string, 1: string, 2: string}>         $cuti        `[nama jenis, mulai, akhir]`
     */
    public static function keterangan(?array $penyesuaian, bool $cltn, ?string $tbInstitusi, array $cuti): string
    {
        $dasar = $tbInstitusi !== null ? trim('(Tugas Belajar) ' . trim($tbInstitusi)) : ($cltn ? self::CLTN : '');

        if ($penyesuaian !== null) {
            $dasar = trim(sprintf('Penyesuaian Tukin %s %s%% (sebelum potongan lain). %s', $penyesuaian['alasan'], $penyesuaian['persen'], $dasar));
        }

        $baris = array_map(static fn (array $c): string => $c[1] === $c[2]
            ? sprintf('%s (%s)', $c[0], self::tanggal($c[1]))
            : sprintf('%s (%s s.d %s)', $c[0], self::tanggal($c[1]), self::tanggal($c[2])), $cuti);

        return implode("\n", array_values(array_filter([$dasar, ...$baris], static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param array{persen: string, alasan: string}|null $penyesuaian
     */
    public static function akhiranTotal(?array $penyesuaian): string
    {
        if ($penyesuaian === null) {
            return '';
        }

        $p = $penyesuaian['persen'];

        return str_starts_with($p, '-') ? ' + ' . substr($p, 1) . '%' : ' - ' . $p . '%';
    }

    /**
     * Persen kanonik tanpa nol berlebih (`-20.00` → `-20`, `-10.50` → `-10.5`).
     */
    public static function persen(int|float|string $nilai): string
    {
        $teks = is_string($nilai) ? trim($nilai) : (is_int($nilai) ? (string) $nilai : rtrim(rtrim(sprintf('%.2F', $nilai), '0'), '.'));

        if (str_contains($teks, '.')) {
            $teks = rtrim(rtrim($teks, '0'), '.');
        }

        return $teks === '-0' ? '0' : $teks;
    }

    private static function tanggal(string $tgl): string
    {
        $t = substr($tgl, 0, 10);

        return substr($t, 8, 2) . ' ' . TanggalBisnis::NAMA_BULAN[(int) substr($t, 5, 2)] . ' ' . substr($t, 0, 4);
    }
}
