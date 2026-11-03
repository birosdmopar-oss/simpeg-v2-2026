<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

/**
 * Pemeta respons e-Kinerja BKN → baris tabel v2 (PRD Fase 6 §7.8, F6-15), murni (tanpa DB/jaringan).
 *
 * - Periode [K `EB:27-44` `cron_periode_skp`]: respons `{success: '1', data: [...]}`; kolom `id, nama, tahun,
 *   periode_awal, periode_akhir, batas_pengisian, jenis_periode`; `bulan` = bagian pertama `periode_akhir` (`MM-DD`),
 *   string kosong → NULL, `status = 1`. Tambahan v2: `periode_akhir`/`periode_awal` wajib `MM-DD` dan `tahun` 4 digit —
 *   pembaca Tukin (`SumberPeriodeTukin::barisBkn`) menolak bentuk lain, jadi baris rusak ditolak di sini, bukan saat Tukin.
 * - SKP periodik [K `EB:260-298`]: 29 kolom; respons `id` → kolom `id_pns`; string kosong → NULL; tanggal diurai
 *   {@see ParserTanggalBkn} (gagal → baris ditolak, BP-10). Kunci logis `id_pns + periode_id + skp_id` wajib terisi
 *   (UNIQUE DBV-027).
 *
 * Penolakan dikembalikan sebagai kode alasan + nomor urut baris saja (tanpa NIP/nama) untuk `integrasi_log`.
 */
final class PemetaEkinerja
{
    /** Kolom respons SKP periodik yang disalin apa adanya (selain `id`, tanggal, `status`) [K `EB:268-296`]. */
    public const KOLOM_SKP = [
        'periode_id', 'skp_id', 'skp_penilaian_id', 'jenis', 'tahun_skp', 'nip', 'nama', 'skp_unor_id', 'skp_unor',
        'skp_unor_induk', 'skp_jabatan', 'skp_jenis_jabatan', 'is_skp_plt_plh_pjb', 'hasil_kerja', 'perilaku_kerja',
        'hasil_akhir', 'pegawai_atasan_id', 'pegawai_atasan_nip', 'pegawai_atasan_nama', 'pegawai_atasan_unor_id',
        'pegawai_atasan_unor', 'pegawai_atasan_jabatan', 'pegawai_atasan_golru', 'pegawai_penilai_id', 'golru',
    ];

    /**
     * @param array<mixed>|null $respons badan JSON referensi/periode
     *
     * @return array{sah: bool, baris: list<array<string, int|string|null>>, ditolak: list<array{urutan: int, alasan: string}>}
     */
    public static function periode(?array $respons): array
    {
        if (! self::sukses($respons)) {
            return ['sah' => false, 'baris' => [], 'ditolak' => []];
        }

        $baris   = [];
        $ditolak = [];

        foreach (array_values((array) $respons['data']) as $i => $r) {
            if (! is_array($r)) {
                $ditolak[] = ['urutan' => $i + 1, 'alasan' => 'bukan_objek'];

                continue;
            }

            $id    = self::teks($r['id'] ?? null);
            $tahun = self::teks($r['tahun'] ?? null);
            $akhir = self::teks($r['periode_akhir'] ?? null);
            $awal  = self::teks($r['periode_awal'] ?? null);

            $alasan = match (true) {
                $id === null || mb_strlen($id) > 150                     => 'id_tidak_sah',
                $tahun === null || preg_match('/^\d{4}$/', $tahun) !== 1 => 'tahun_tidak_sah',
                $akhir === null || ! self::bulanHari($akhir)             => 'periode_akhir_tidak_sah',
                $awal !== null && ! self::bulanHari($awal)               => 'periode_awal_tidak_sah',
                default                                                  => null,
            };

            if ($alasan !== null) {
                $ditolak[] = ['urutan' => $i + 1, 'alasan' => $alasan];

                continue;
            }

            $baris[] = [
                'id'              => $id,
                'nama'            => self::teks($r['nama'] ?? null) ?? '',
                'tahun'           => (int) $tahun,
                'bulan'           => substr((string) $akhir, 0, 2),
                'periode_awal'    => $awal,
                'periode_akhir'   => $akhir,
                'batas_pengisian' => self::teks($r['batas_pengisian'] ?? null),
                'jenis_periode'   => self::teks($r['jenis_periode'] ?? null),
                'status'          => 1,
            ];
        }

        return ['sah' => true, 'baris' => $baris, 'ditolak' => $ditolak];
    }

    /**
     * @param array<mixed>|null $respons badan JSON laporan/penilaian/{tahun}/{periode}
     *
     * @return array{sah: bool, baris: list<array<string, string|null>>, ditolak: list<array{urutan: int, alasan: string}>}
     */
    public static function skpPeriodik(?array $respons): array
    {
        if (! self::sukses($respons)) {
            return ['sah' => false, 'baris' => [], 'ditolak' => []];
        }

        $baris   = [];
        $ditolak = [];

        foreach (array_values((array) $respons['data']) as $i => $r) {
            if (! is_array($r)) {
                $ditolak[] = ['urutan' => $i + 1, 'alasan' => 'bukan_objek'];

                continue;
            }

            $b = ['id_pns' => self::teks($r['id'] ?? null)];

            foreach (self::KOLOM_SKP as $k) {
                $b[$k] = self::teks($r[$k] ?? null);
            }

            $alasan = null;

            foreach (['id_pns', 'periode_id', 'skp_id', 'nip', 'nama'] as $wajib) {
                if ($b[$wajib] === null) {
                    $alasan = $wajib . '_kosong';

                    break;
                }
            }

            foreach (['periode_awal_skp' => false, 'periode_akhir_skp' => false, 'waktu_dinilai' => true] as $kolom => $jam) {
                $mentah    = self::teks($r[$kolom] ?? null);
                $b[$kolom] = $mentah === null ? null : ParserTanggalBkn::urai($mentah, $jam);

                if ($mentah !== null && $b[$kolom] === null) {
                    $alasan ??= $kolom . '_tidak_sah';
                }
            }

            if ($alasan !== null) {
                $ditolak[] = ['urutan' => $i + 1, 'alasan' => $alasan];

                continue;
            }

            $b['status'] = '1';
            $baris[]     = $b;
        }

        return ['sah' => true, 'baris' => $baris, 'ditolak' => $ditolak];
    }

    /**
     * Kunci logis legacy `id_pns**periode_id**skp_id` [K `EB:245/263`].
     *
     * @param array<string, int|string|null> $b
     */
    public static function kunci(array $b): string
    {
        return $b['id_pns'] . '**' . $b['periode_id'] . '**' . $b['skp_id'];
    }

    /**
     * @param array<mixed>|null $respons
     */
    private static function sukses(?array $respons): bool
    {
        return is_array($respons) && (string) ($respons['success'] ?? '') === '1' && is_array($respons['data'] ?? null);
    }

    private static function bulanHari(string $nilai): bool
    {
        return preg_match('/^(\d{2})-(\d{2})$/', $nilai, $m) === 1 && checkdate((int) $m[1], (int) $m[2], 2024);
    }

    private static function teks(mixed $nilai): ?string
    {
        if ($nilai === null || is_array($nilai) || is_object($nilai)) {
            return null;
        }

        $s = trim((string) $nilai);

        return $s === '' ? null : $s;
    }
}
