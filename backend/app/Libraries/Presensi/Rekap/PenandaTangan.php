<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Rekap;

/**
 * Blok penanda tangan laporan Tukin (PRD 5B §7.3.4, F5B-13) — **murni**: menentukan kasus dan teks yang **selalu dicetak**
 * dari data yang sudah dimuat `Laporan\PejabatPenandaTangan` (port `L_presensi.php:12018-12140` legacy `0cf7e67`).
 *
 * | Kasus | Jabatan | Nama / NIP |
 * |---|---|---|
 * | `definitif` | jabatan struktural | nama bergelar / NIP |
 * | `plt` | "Plt. <jabatan>" | "Plt: <nama bergelar>" / NIP Plt |
 * | `jabatan_kosong` | jabatan struktural | "Jabatan kosong (belum ada pejabat definitif/Plt)" / kosong |
 * | `pejabat_tidak_ditemukan` | "Pejabat tidak ditemukan" | kosong / kosong |
 * | `menteri` | `jabatan_menteri_full` | `nama_menteri` / kosong |
 * | `menteri_belum_diatur` | "Data menteri belum diatur di Web Config" | kosong / kosong |
 *
 * **[CATAT] F2 (keputusan Horii 28-10-2026) — Plt penanda tangan**
 *
 * | v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
 * |---|---|---|
 * | Plt `pegawai_plt` jabatan itu yang berlaku pada tanggal cetak (`tgl_mulai ≤ tanggal ≤ tgl_akhir`, akhir kosong = masih berlaku) **dan** `status = 1`; tanpa Plh (preseden `StrukturService`); lebih dari satu → `tgl_mulai` terbaru, lalu `id_pegawai_plt` terbesar ({@see self::pilihPlt()}) | `getPLT($idJabatan, false, date("Y-m-d"))` (`L_presensi.php:12041`, `function_helper.php:1946`): tanpa saring status, `row_array()` = baris pertama hasil kueri tanpa urutan terjamin | v2 tidak memakai Plt nonaktif/dihapus; pilihan deterministik dan teruji. Plh tidak dipakai (sama dengan legacy) |
 */
final class PenandaTangan
{
    public const DEFINITIF = 'definitif';

    public const PLT = 'plt';

    public const JABATAN_KOSONG = 'jabatan_kosong';

    public const PEJABAT_TIDAK_DITEMUKAN = 'pejabat_tidak_ditemukan';

    public const MENTERI = 'menteri';

    public const MENTERI_BELUM_DIATUR = 'menteri_belum_diatur';

    private function __construct()
    {
    }

    /**
     * @param string|null                           $jabatan   jabatan struktural lingkup (null = tidak ditemukan)
     * @param array{nama: string, nip: string}|null $definitif
     * @param array{nama: string, nip: string}|null $plt
     *
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    public static function struktural(?string $jabatan, ?array $definitif, ?array $plt): array
    {
        $jabatan = $jabatan === null ? '' : trim($jabatan);

        if ($jabatan === '') {
            return self::blok(self::PEJABAT_TIDAK_DITEMUKAN, 'Pejabat tidak ditemukan', '', '');
        }

        if ($definitif !== null) {
            return self::blok(self::DEFINITIF, $jabatan, $definitif['nama'], $definitif['nip']);
        }

        if ($plt !== null) {
            return self::blok(self::PLT, 'Plt. ' . $jabatan, 'Plt: ' . $plt['nama'], $plt['nip']);
        }

        return self::blok(self::JABATAN_KOSONG, $jabatan, 'Jabatan kosong (belum ada pejabat definitif/Plt)', '');
    }

    /**
     * Lingkup kementerian (`0`) dan IL: menteri dari `web_config` [K `:12074-12080`].
     *
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    public static function menteri(?string $jabatanMenteri, ?string $namaMenteri): array
    {
        $jabatan = trim((string) $jabatanMenteri);
        $nama    = trim((string) $namaMenteri);

        return $jabatan === '' || $nama === ''
            ? self::blok(self::MENTERI_BELUM_DIATUR, 'Data menteri belum diatur di Web Config', '', '')
            : self::blok(self::MENTERI, $jabatan, $nama, '');
    }

    /**
     * Plt berlaku pada `$tanggal` (`tgl_mulai ≤ tanggal ≤ tgl_akhir`; `tgl_mulai`/`tgl_akhir` kosong = tak terbatas di
     * sisi itu). Pemanggil sudah menyaring `status = 1` dan jabatan. Lebih dari satu → `tgl_mulai` terbaru, lalu
     * `id_pegawai_plt` terbesar (F2).
     *
     * @param list<array{id_pegawai_plt: int|string, tgl_mulai: ?string, tgl_akhir: ?string}> $kandidat
     *
     * @return array<string, mixed>|null baris terpilih
     */
    public static function pilihPlt(array $kandidat, string $tanggal): ?array
    {
        $berlaku = array_values(array_filter($kandidat, static fn (array $p): bool => ((string) ($p['tgl_mulai'] ?? '')) <= $tanggal
            && ((string) ($p['tgl_akhir'] ?? '') === '' || (string) $p['tgl_akhir'] >= $tanggal)));

        usort($berlaku, static fn (array $a, array $b): int => [(string) ($b['tgl_mulai'] ?? ''), (int) $b['id_pegawai_plt']]
            <=> [(string) ($a['tgl_mulai'] ?? ''), (int) $a['id_pegawai_plt']]);

        return $berlaku[0] ?? null;
    }

    /**
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    private static function blok(string $kasus, string $jabatan, string $nama, string $nip): array
    {
        return ['kasus' => $kasus, 'jabatan' => $jabatan, 'nama' => $nama, 'nip' => $nip];
    }
}
