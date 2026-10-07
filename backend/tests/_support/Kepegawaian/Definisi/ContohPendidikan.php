<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian\Definisi;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\MasterData\MasterField;

/**
 * Definisi CONTOH untuk test S0-A (MAKE-002) — bukan Definisi nyata B-10 (milik WS-1 M2). Menunjukkan bentuk data
 * kontrak: izin per role (Matriks v2 `hr/rwy/pendidikan/*` = 1, 2, 3, 6, 7), alur self-service, snapshot (dok DBV-012
 * §5.1), dan lampiran per kode `jenis_rwy` (14 pendidikan, 39 pencantuman gelar, 40 transkrip nilai; 5 MB).
 */
final class ContohPendidikan extends RiwayatDefinisi
{
    public function jenis(): string
    {
        return 'pendidikan';
    }

    public function label(): string
    {
        return 'Riwayat Pendidikan';
    }

    public function tabel(): string
    {
        return 'riwayat_pendidikan';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_pendidikan';
    }

    public function fields(): array
    {
        return [
            new MasterField('tgl_lulus', 'Tanggal Lulus', MasterField::TYPE_DATE, required: true),
            new MasterField('institusi_pendidikan', 'Institusi', maxBytes: 255),
            new MasterField('no_ijazah', 'Nomor Ijazah', maxBytes: 100),
        ];
    }

    public function izin(): array
    {
        $semua = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];
        $admin = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

        return [
            'lihat'  => $semua,
            'tambah' => $semua,
            'ubah'   => $semua,
            'hapus'  => $admin,
            'proses' => $admin,
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::SelfService;
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot(
                tabel: 'pegawai_pendidikan',
                kolom: ['id_riwayat_pendidikan', 'tgl_lulus', 'glr_awal', 'glr_akhir'],
                urutan: ['jenjang_pendidikan.order' => 'DESC', 'tgl_lulus' => 'DESC'],
                join: ['jenjang_pendidikan' => 'jenjang_pendidikan.id_jenjang_pendidikan = riwayat_pendidikan.id_jenjang_pendidikan'],
            ),
        ];
    }

    public function lampiran(): array
    {
        // Kode jenis_rwy riwayat_pendidikan (seed main) — legacy L_pendidikan.php: tiga arsip per entri, masing-masing 5 MB.
        return [
            new AturanLampiran(14, true, 5, ['pdf']),  // pendidikan (ijazah)
            new AturanLampiran(39, false, 5, ['pdf']), // pencantuman_gelar
            new AturanLampiran(40, false, 5, ['pdf']), // transkrip_nilai
        ];
    }

    public function urutanTab(): int
    {
        return 20;
    }
}
