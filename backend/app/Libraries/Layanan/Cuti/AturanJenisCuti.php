<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 M3 — siapa boleh memilih jenis cuti apa (PRD Fase 4 §6.2.2, port `setJenisCuti` `L_cuti.php:281-391` dan
 * validasi server `L:1551-1576`). Murni: fakta pegawai dan pengajuan diterima sebagai parameter.
 */
final class AturanJenisCuti
{
    public const ID_PNS = 1;

    public const ID_PPPK = 6;

    /** `id_jenis_kp` CPNS (dan kosong) tidak berhak cuti Tahunan [K `L:313-321`]. */
    public const JENIS_KP_CPNS = 1;

    public const MKER_BESAR_MINIMAL = 5;

    public const PESAN_MKER_BESAR = 'Cuti Besar dan CTLN dapat diambil bila masa kerja pegawai sudah minimal 5 tahun.';

    public const PESAN_KEHILANGAN_TAHUNAN = 'Pegawai yang telah mengajukan cuti besar akan kehilangan hak Cuti Tahunan (12 hari) pada tahun berjalan.';

    public const PESAN_JENIS_TIDAK_TERSEDIA = 'Jenis cuti tidak tersedia untuk pegawai ini.';

    private function __construct()
    {
    }

    /**
     * Id jenis yang boleh dipilih, urut naik.
     *
     * @param list<int>   $jenisAktif         id `jenis_cuti` berstatus aktif
     * @param int|null    $tahunMutasiPertama selisih tahun penuh sejak TMT mutasi jabatan pertama (PPPK); null = belum ada
     * @param bool        $adaCutiBesarTahunIni Cuti Besar disetujui yang `tgl_mulai`-nya di tahun berjalan
     * @param int|null    $idJenisKp          jenis kenaikan pangkat terakhir (null/0 = kosong)
     * @param int|null    $jenisKelamin       1 laki-laki, 2 perempuan
     * @param bool        $inputAdmin         role 1 (CLTN hanya lewat input admin)
     *
     * @return list<int>
     */
    public static function jenisBoleh(
        array $jenisAktif,
        int $idJenisPegawai,
        ?int $idJenisKp,
        int $mkerTh,
        ?int $jenisKelamin,
        ?int $tahunMutasiPertama,
        bool $adaCutiBesarTahunIni,
        bool $inputAdmin,
    ): array {
        $boleh = array_fill_keys($jenisAktif, true);
        $pppk  = $idJenisPegawai === self::ID_PPPK;

        // 1 Tahunan
        if ($pppk) {
            if ($tahunMutasiPertama === null || $tahunMutasiPertama < 1) {
                unset($boleh[AturanCuti::JENIS_TAHUNAN]);
            }
        } elseif ($idJenisPegawai !== self::ID_PNS || $idJenisKp === null || $idJenisKp === 0 || $idJenisKp === self::JENIS_KP_CPNS || $mkerTh < 1) {
            unset($boleh[AturanCuti::JENIS_TAHUNAN]);
        }

        if ($adaCutiBesarTahunIni) {
            unset($boleh[AturanCuti::JENIS_TAHUNAN]);
        }

        // 2 Besar dan 6 CLTN
        if ($pppk || $mkerTh < self::MKER_BESAR_MINIMAL) {
            unset($boleh[AturanCuti::JENIS_BESAR], $boleh[AturanCuti::JENIS_CLTN]);
        }

        if (! $inputAdmin) {
            unset($boleh[AturanCuti::JENIS_CLTN]);
        }

        // 4 Melahirkan: perempuan saja
        if ($jenisKelamin === 1) {
            unset($boleh[AturanCuti::JENIS_MELAHIRKAN]);
        }

        // 5 Alasan Penting: bukan PPPK
        if ($pppk) {
            unset($boleh[AturanCuti::JENIS_ALASAN_PENTING]);
        }

        $ids = array_keys($boleh);
        sort($ids);

        return $ids;
    }

    /**
     * Kelompok tumpang tindih (PRD §6.2.3): Sakit/Alasan Penting hanya terhadap jenis 3/5 berstatus {0, 1, 3}; jenis lain
     * terhadap semua jenis berstatus {0, 1, 3, 4}.
     *
     * @return array{jenis: list<int>|null, status: list<int>}
     */
    public static function kelompokTumpangTindih(int $jenis): array
    {
        return in_array($jenis, [AturanCuti::JENIS_SAKIT, AturanCuti::JENIS_ALASAN_PENTING], true)
            ? ['jenis' => [AturanCuti::JENIS_SAKIT, AturanCuti::JENIS_ALASAN_PENTING], 'status' => [0, 1, 3]]
            : ['jenis' => null, 'status' => [0, 1, 3, 4]];
    }

    /** Pesan tumpang tindih [V2 keputusan #5]. */
    public static function pesanTumpangTindih(): string
    {
        return 'Tanggal cuti beririsan dengan pengajuan cuti lain.';
    }
}
