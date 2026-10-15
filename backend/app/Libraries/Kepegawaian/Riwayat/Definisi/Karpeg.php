<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;

/**
 * B-17 Usulan Kartu Pegawai (WS-1 M4 MAKE-007) — alur usulan mandiri (halaman sendiri, bukan tab).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Karpeg.php`, `libraries/hr/rwy/L_karpeg.php` (`vp()`
 * :796-880, `list_ad()` :22-43, `sp_process()` :1077-1087), views `views/hr/employee/rwy/karpeg/*`. Keputusan user
 * 12-10-2026:
 * - Ajukan/ubah/hapus: pemilik role 2, 7 (legacy userAuth); ubah & hapus hanya saat status 0/2 (kunci baris Disetujui).
 * - Antrean (`GET usulan/karpeg`) role 1, 4, 5 tanpa lingkup; role 4/5 hanya lihat/detail (legacy `list_ad`).
 * - [V2] Pemroses = role 1 sampai `petugas_layanan` (Fase 4, C-01) ada — legacy: role 2 yang terdaftar petugas layanan.
 *   TODO(C-01): alihkan izin proses ke petugas layanan.
 * - [V2] Hapus = status 10 (legacy hapus keras).
 * - PDF tanda terima: `GET usulan/{jenis}/{id}/tanda-terima` (WS-2 MAKE-013, `Usulan\TandaTerimaUsulan`, dibangkitkan
 *   saat diminta untuk usulan Disetujui; `file_tanda_terima` tidak diisi).
 * Berkas `file_1..5` (+ `file_surat_hilang` wajib untuk permohonan karena kehilangan) disimpan di kolom lewat
 * `kolomBerkas()` (KolomBerkas MAKE-009); 5 MB, jpg|jpeg|png|gif|pdf|xls|xlsx|doc|docx (legacy).
 */
final class Karpeg extends RiwayatDefinisi
{
    public const PERTAMA      = 'Permohonan Pertama';
    public const KEHILANGAN   = 'Karena Kehilangan';
    public const SURAT_HILANG = 'file_surat_hilang';

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx'];

    /**
     * Label berkas (legacy `karpeg/form.php`).
     *
     * @var array<string, string>
     */
    public const BERKAS = [
        'file_1'           => 'SK CPNS',
        'file_2'           => 'SK PNS',
        'file_3'           => 'SPMT',
        'file_4'           => 'Sertifikat Prajabatan',
        'file_5'           => 'Pas Foto',
        self::SURAT_HILANG => 'Surat Keterangan Hilang',
    ];

    public function jenis(): string
    {
        return 'karpeg';
    }

    public function label(): string
    {
        return 'Usulan Kartu Pegawai';
    }

    public function tabel(): string
    {
        return 'riwayat_karpeg';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_karpeg';
    }

    public function fields(): array
    {
        return [
            new MasterField('jenis_permohonan', 'Jenis Permohonan', MasterField::TYPE_SELECT, required: true, options: [
                self::PERTAMA => self::PERTAMA, self::KEHILANGAN => self::KEHILANGAN,
            ]),
        ];
    }

    public function izin(): array
    {
        $pemilik = [Role::PEGAWAI, Role::PPPK];

        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PPPK],
            'tambah' => $pemilik,
            'ubah'   => $pemilik,
            'hapus'  => $pemilik,
            'proses' => [Role::SUPER_ADMIN],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Usulan;
    }

    /**
     * CHECK `riwayat_karpeg.status IN (0, 1, 2, 10)`.
     */
    public function pemetaanStatus(): array
    {
        return self::statusTanpaDiproses();
    }

    /**
     * Terbaru dulu (legacy `list_ad()` `updated_at DESC`).
     */
    public function urutanDaftar(): array
    {
        return ['updated_at' => 'DESC', $this->primaryKey() => 'DESC'];
    }

    public function kolomBerkas(): array
    {
        $aturan = [];

        foreach (array_keys(self::BERKAS) as $kolom) {
            $aturan[$kolom] = new AturanLampiran(0, $kolom !== self::SURAT_HILANG, 5, self::EKSTENSI);
        }

        return $aturan;
    }

    /**
     * Surat keterangan hilang wajib hanya untuk permohonan karena kehilangan (legacy `vp()`).
     */
    public function kolomBerkasWajib(string $kolom, AturanLampiran $aturan, array $nilai): bool
    {
        return $kolom === self::SURAT_HILANG ? ($nilai['jenis_permohonan'] ?? null) === self::KEHILANGAN : $aturan->wajib;
    }

    /**
     * @return array<int, StatusRiwayat>
     */
    public static function statusTanpaDiproses(): array
    {
        return [
            StatusRiwayat::Menunggu->value  => StatusRiwayat::Menunggu,
            StatusRiwayat::Disetujui->value => StatusRiwayat::Disetujui,
            StatusRiwayat::Ditolak->value   => StatusRiwayat::Ditolak,
            StatusRiwayat::Dihapus->value   => StatusRiwayat::Dihapus,
        ];
    }
}
