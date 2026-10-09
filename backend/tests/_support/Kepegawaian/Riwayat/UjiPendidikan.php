<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\MasterData\MasterField;

/**
 * Definisi UJI engine M1 (MAKE-004) — bukan Definisi nyata B-10 (M2). Self-service (pegawai mengajukan, admin
 * memproses), snapshot `pegawai_pendidikan` jenjang tertinggi (dok DBV-012 §5.1: `jenjang_pendidikan.order DESC,
 * tgl_lulus DESC`, lewat join), field ref master, lampiran (14 wajib, 39 opsional), dan hook yang bisa diatur per test.
 */
final class UjiPendidikan extends RiwayatDefinisi
{
    /**
     * @var list<array{0: string, 1: array<string, mixed>}> hook yang dipanggil: [nama, baris/data]
     */
    public array $panggilanHook = [];

    /**
     * @var array<string, list<string>> hasil hook validate()
     */
    public array $errorValidate = [];

    public bool $lampiranWajib = false;

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
            new MasterField('id_jenjang_pendidikan', 'Jenjang', MasterField::TYPE_REF, entity: 'jenjang-pendidikan'),
            new MasterField('tgl_lulus', 'Tanggal Lulus', MasterField::TYPE_DATE, required: true),
            new MasterField('institusi_pendidikan', 'Institusi', maxBytes: 255),
            new MasterField('glr_akhir', 'Gelar Akhir', maxBytes: 50),
        ];
    }

    public function izin(): array
    {
        $semua = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];
        $admin = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

        return [
            'lihat'  => [...$semua, Role::ADMIN_VIEW_ESELON1],
            'tambah' => $semua,
            'ubah'   => $semua,
            'hapus'  => $semua,
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
                kolom: ['id_riwayat_pendidikan', 'id_jenjang_pendidikan', 'tgl_lulus', 'institusi_pendidikan', 'glr_akhir'],
                urutan: ['jenjang_pendidikan.order' => 'DESC', 'tgl_lulus' => 'DESC'],
                join: ['jenjang_pendidikan' => 'jenjang_pendidikan.id_jenjang_pendidikan = riwayat_pendidikan.id_jenjang_pendidikan'],
            ),
        ];
    }

    public function lampiran(): array
    {
        return [
            new AturanLampiran(14, $this->lampiranWajib, 5, ['pdf']),
            new AturanLampiran(39, false, 1, ['pdf']),
        ];
    }

    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $this->panggilanHook[] = ['validate', $data];

        return $this->errorValidate;
    }

    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $this->panggilanHook[] = ['beforeSave', $data];

        // Kolom turunan (pola legacy set_param: teks dari master).
        if (array_key_exists('id_jenjang_pendidikan', $data)) {
            $row                                = db_connect()->table('jenjang_pendidikan')->where('id_jenjang_pendidikan', $data['id_jenjang_pendidikan'])->get()->getRowArray();
            $data['jenjang_pendidikan_singkat'] = $row['jenjang_pendidikan_singkat'] ?? null;
        }

        return $data;
    }

    public function afterApprove(array $baris, AuthContext $auth): void
    {
        $this->panggilanHook[] = ['afterApprove', $baris];
    }
}
