<?php

declare(strict_types=1);

namespace Tests\Support\Sintetis;

use InvalidArgumentException;

/**
 * Profil volume data sintetis riwayat dalam (CR-168). Semua angka = jumlah baris yang dibangkitkan; tidak ada data nyata.
 *
 * - `jumlahPegawai` pegawai sintetis, sebagian (`jumlahDalam`) berriwayat "dalam" (berlipat `faktorDalam`).
 * - `mutasi`/`kp` = rentang [min, max] baris riwayat_mutasi_jabatan / riwayat_kp per pegawai biasa.
 * - `lkh` = jumlah hari kerja LKH per pegawai biasa, mundur dari `tanggalAkhir` (riwayat_lckh).
 * - `persenKembar` = persen pegawai yang punya dua baris riwayat aktif dengan `tmtsk` sama (tie-breaker PK).
 * - `persenKeluar` = persen pegawai status 2 (keluar) dengan `tmt_status` di 24 bulan terakhir.
 * - `bawahanPerAtasan` = ukuran kelompok LKH; pegawai pertama kelompok = atasan (`nip_atasan`) anggota lain.
 * - `idJenisPegawai` / `idJenisStatus` = nilai `pegawai.id_jenis_pegawai` / `id_jenis_status` untuk pegawai aktif (null bila
 *   master belum diisi; keduanya FK).
 *
 * @internal
 */
final class ProfilSintetis
{
    /**
     * @param array{0: int, 1: int} $mutasi
     * @param array{0: int, 1: int} $kp
     */
    public function __construct(
        public readonly int $jumlahPegawai,
        public readonly array $mutasi = [3, 10],
        public readonly array $kp = [2, 7],
        public readonly int $lkh = 320,
        public readonly int $jumlahDalam = 0,
        public readonly int $faktorDalam = 5,
        public readonly int $persenKembar = 5,
        public readonly int $persenKeluar = 5,
        public readonly int $bawahanPerAtasan = 8,
        public readonly string $tanggalAkhir = '2026-10-30',
        public readonly ?int $idJenisPegawai = null,
        public readonly ?int $idJenisStatus = null,
        public readonly int $benih = 2026,
    ) {
        if ($jumlahPegawai < 1 || $jumlahPegawai > 99_999_999) {
            throw new InvalidArgumentException('jumlahPegawai di luar 1..99.999.999.');
        }

        if ($mutasi[0] < 1 || $mutasi[0] > $mutasi[1] || $kp[0] < 1 || $kp[0] > $kp[1] || $lkh < 0) {
            throw new InvalidArgumentException('Rentang mutasi/kp/lkh tidak sah.');
        }

        if ($jumlahDalam < 0 || $jumlahDalam > $jumlahPegawai || $faktorDalam < 1 || $bawahanPerAtasan < 2) {
            throw new InvalidArgumentException('jumlahDalam/faktorDalam/bawahanPerAtasan tidak sah.');
        }
    }

    /** Profil kecil untuk test (cepat, tetap memuat kasus kembar/keluar/dalam). */
    public static function kecil(int $benih = 2026): self
    {
        return new self(jumlahPegawai: 40, mutasi: [2, 5], kp: [1, 4], lkh: 30, jumlahDalam: 2, faktorDalam: 4, persenKembar: 20, persenKeluar: 20, bawahanPerAtasan: 5, benih: $benih);
    }

    /** Profil riset EXPLAIN (±5.000 pegawai, ±1,6 juta LKH, riwayat dalam 1%), selaras asumsi konteks optimasi. */
    public static function riset(?int $idJenisPegawai = null, ?int $idJenisStatus = null): self
    {
        return new self(jumlahPegawai: 5000, jumlahDalam: 50, faktorDalam: 6, idJenisPegawai: $idJenisPegawai, idJenisStatus: $idJenisStatus);
    }
}
