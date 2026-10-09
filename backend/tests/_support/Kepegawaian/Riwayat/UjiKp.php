<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\MasterData\MasterField;

/**
 * Definisi UJI engine M1 (MAKE-004) — bukan Definisi nyata B-08 (M2). Alur admin (legacy `controllers/hr/rwy/Kp.php`:
 * tambah role 1, ubah/hapus 1 & 3) dengan snapshot MULTI-TARGET dok DBV-012 §5.1 dan nama master yang disalin lewat
 * hook beforeSave (templat Definisi nyata B-08):
 *  - `pegawai_kp`: status 1 AND `id_jenis_kp != 6`, `tmtsk DESC`;
 *  - `pegawai_cpns`: status 1 AND `id_jenis_kp = 1`, `tmtsk ASC`;
 *  - `pegawai_pns`: status 1 AND `id_jenis_kp = 2`, `tmtsk ASC`.
 */
final class UjiKp extends RiwayatDefinisi
{
    private const KOLOM = [
        'id_riwayat_kp', 'id_jenis_kp', 'id_pangkat', 'tmtsk', 'tgl_sk', 'no_sk', 'jenis_kp', 'gol', 'ruang', 'gol_ruang',
        'pangkat',
    ];

    public function jenis(): string
    {
        return 'kp';
    }

    public function label(): string
    {
        return 'Riwayat Kenaikan Pangkat';
    }

    public function tabel(): string
    {
        return 'riwayat_kp';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_kp';
    }

    public function fields(): array
    {
        return [
            new MasterField('id_jenis_kp', 'Jenis KP', MasterField::TYPE_REF, required: true, entity: 'jenis-kp'),
            new MasterField('id_pangkat', 'Pangkat/Golongan', MasterField::TYPE_REF, required: true, entity: 'pangkat'),
            new MasterField('tmtsk', 'TMT', MasterField::TYPE_DATE, required: true),
            new MasterField('tgl_sk', 'Tanggal SK', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk', 'Nomor SK', maxBytes: 100),
        ];
    }

    /**
     * Salin nama master ke kolom teks riwayat (legacy `L_kp.php:551-560` set_param): `jenis_kp` dari `jenis_kp`,
     * `gol`/`ruang`/`gol_ruang`/`pangkat` dari `pangkat` — diturunkan server, bukan dikirim klien. Hanya bila rujukannya
     * ada di $data (ubah parsial tanpa rujukan tidak menyentuh kolom turunan).
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $db = db_connect();

        if (array_key_exists('id_jenis_kp', $data)) {
            $jenis            = $db->table('jenis_kp')->where('id_jenis_kp', $data['id_jenis_kp'])->get()->getRowArray();
            $data['jenis_kp'] = $jenis['jenis_kp'] ?? null;
        }

        if (array_key_exists('id_pangkat', $data)) {
            $pangkat = $db->table('pangkat')->where('id_pangkat', $data['id_pangkat'])->get()->getRowArray();

            foreach (['gol', 'ruang', 'gol_ruang', 'pangkat'] as $kolom) {
                $data[$kolom] = $pangkat[$kolom] ?? null;
            }
        }

        return $data;
    }

    public function izin(): array
    {
        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN],
            'tambah' => [Role::SUPER_ADMIN],
            'ubah'   => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
            'hapus'  => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
            'proses' => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_kp', self::KOLOM, filter: ['id_jenis_kp !=' => 6], urutan: ['tmtsk' => 'DESC']),
            new AturanSnapshot('pegawai_cpns', self::KOLOM, filter: ['id_jenis_kp' => 1], urutan: ['tmtsk' => 'ASC']),
            new AturanSnapshot('pegawai_pns', self::KOLOM, filter: ['id_jenis_kp' => 2], urutan: ['tmtsk' => 'ASC']),
        ];
    }
}
