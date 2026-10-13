<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\Database\BaseBuilder;

/**
 * B-12a SKP periodik (WS-1 M5 MAKE-008): data hasil tarik e-Kinerja/SIASN (`riwayat_skp_periodik`), tanpa snapshot.
 *
 * Sumber [K] = kode legacy (`application/`): `libraries/hr/rwy/L_skp.php` periodic() :773-798 (daftar hanya status 1),
 * form/unggah arsip :800-935 (PDF, 1 MB, kolom `f_arsip_1`; status tidak berubah). Tidak ada isian: satu-satunya ubah =
 * unggah arsip, oleh role 1. Tanpa tambah/hapus/proses. Deviasi dari teks PRD (tambah/proses SKP periodik) = [K] ikut
 * legacy, keputusan user 13-10-2026. Belum dibawa: hapus arsip legacy (:937-975, TODO tahap berikut) dan rincian periode
 * `bkn_periode_ekinper` (join daftar legacy).
 */
final class SkpPeriodik extends RiwayatDefinisi
{
    public const KOLOM_ARSIP = 'f_arsip_1';

    public function jenis(): string
    {
        return 'skp-periodik';
    }

    public function label(): string
    {
        return 'SKP Periodik';
    }

    public function tabel(): string
    {
        return 'riwayat_skp_periodik';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_skp_periodik';
    }

    public function fields(): array
    {
        return [];
    }

    public function izin(): array
    {
        return [
            'lihat' => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK],
            'ubah'  => [Role::SUPER_ADMIN],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    /**
     * CHECK `status IN (0, 1, 2, 10)` — tanpa 3 Diproses.
     */
    public function pemetaanStatus(): array
    {
        return Karpeg::statusTanpaDiproses();
    }

    /**
     * Legacy hanya menampilkan baris status 1 (`L_skp.php:779`).
     */
    public function saringDaftar(BaseBuilder $builder): void
    {
        $builder->where($this->kolomStatus(), StatusRiwayat::Disetujui->value);
    }

    /**
     * [V2] urut periode (legacy tanpa ORDER BY).
     */
    public function urutanDaftar(): array
    {
        return ['tahun_skp' => 'ASC', 'periode_awal_skp' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    /**
     * Arsip SKP periodik: PDF 1 MB, wajib saat unggah (legacy `L_skp.php` :858-868).
     */
    public function kolomBerkas(): array
    {
        return [self::KOLOM_ARSIP => new AturanLampiran(0, true, 1, ['pdf'])];
    }

    public function urutanTab(): int
    {
        return 81;
    }
}
