<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\JenisKp;
use App\Libraries\Kepegawaian\Kalkulasi\KenaikanPangkat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\BaseBuilder;

/**
 * B-08 Riwayat Kenaikan Pangkat (WS-1 M2 MAKE-005) — pilot multi-target mesin riwayat.
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Kp.php`, `libraries/hr/rwy/L_kp.php`
 * (`validate_param()` :422-536, `set_param()` :538-566, `list()` :27, unggah :118-131). Snapshot multi-target: dok
 * DBV-012 §5.1 (`pegawai_kp`, `pegawai_cpns`, `pegawai_pns`). Hak akses: Matriks v2 `hr/rwy/kp/*` = 1, 3 (Admin),
 * 2, 4, 5 (View); legacy `index()` juga membuka role 8 — tidak dibawa (Matriks v2, keputusan #4).
 *
 * [V2] SRS-FR-025 (CR-025): TMT KP wajib 1 April / 1 Oktober kecuali pengangkatan CPNS/PNS
 * (`KenaikanPangkat::validasiTmt` + `JenisKp::wajibTmtPeriode`), hanya untuk input pengguna — tambah, atau ubah yang
 * mengubah `tmtsk`/`id_jenis_kp` (K-CR025-10). Legacy tidak memvalidasi ini.
 */
final class Kp extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran SK KP (konstanta legacy `ARSIP_RWY['kp']`, `config/constants.php:200`).
     */
    public const LAMPIRAN_SK_KP = 11;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * Kolom yang disalin ke ketiga snapshot (kolom bersama `riwayat_kp` dan `pegawai_kp/_cpns/_pns`).
     *
     * @var list<string>
     */
    private const KOLOM_SNAPSHOT = [
        'id_riwayat_kp', 'id_jenis_kp', 'id_pangkat', 'tmtsk', 'tgl_sk', 'no_sk', 'tgl_pertek_bkn', 'no_pertek_bkn',
        'jumlah_kredit_utama', 'jumlah_kredit_tambahan', 'jenis_kp', 'gol', 'ruang', 'gol_ruang', 'pangkat', 'mker_th',
        'mker_bl', 'gaji_pokok', 'keterangan', 'id_rw_siasn',
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

    /**
     * Field form legacy (`views/hr/employee/rwy/kp/form.php`), semuanya wajib kecuali keterangan (`validate_param()`
     * :429-460). Nama jenis/pangkat/golongan diturunkan server (beforeSave).
     */
    public function fields(): array
    {
        return [
            new MasterField('id_jenis_kp', 'Jenis KP', MasterField::TYPE_REF, required: true, entity: 'jenis-kp'),
            new MasterField('id_pangkat', 'Golongan', MasterField::TYPE_REF, required: true, entity: 'pangkat'),
            new MasterField('tmtsk', 'TMT SK', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk', 'Nomor SK', required: true, maxBytes: 100),
            new MasterField('tgl_sk', 'Tanggal SK', MasterField::TYPE_DATE, required: true),
            new MasterField('gaji_pokok', 'Gaji Pokok', MasterField::TYPE_DECIMAL, required: true, min: 0),
            new MasterField('mker_th', 'Masa Kerja (Tahun)', MasterField::TYPE_INT, required: true, columnType: 'tinyint'),
            new MasterField('mker_bl', 'Masa Kerja (Bulan)', MasterField::TYPE_INT, required: true, columnType: 'tinyint'),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $admin = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI],
            'tambah' => $admin,
            'ubah'   => $admin,
            'hapus'  => $admin,
            'proses' => $admin,
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    /**
     * Legacy: role 1 memilih status saat edit KP (`views/hr/employee/rwy/kp/form.php:59-74`, pilihan Disetujui/Ditolak/
     * Diproses/Waiting; keputusan user 09-10-2026 ikut legacy). Diproses hanya bila flag status 3 aktif.
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :27 `ORDER BY status ASC, tmtsk ASC` (legacy hanya status 0/1; kontrak v2 semua kecuali 10).
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tmtsk' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_kp', self::KOLOM_SNAPSHOT, filter: ['id_jenis_kp !=' => 6], urutan: ['tmtsk' => 'DESC']),
            new AturanSnapshot('pegawai_cpns', self::KOLOM_SNAPSHOT, filter: ['id_jenis_kp' => JenisKp::PENGANGKATAN_CPNS], urutan: ['tmtsk' => 'ASC']),
            new AturanSnapshot('pegawai_pns', self::KOLOM_SNAPSHOT, filter: ['id_jenis_kp' => JenisKp::PENGANGKATAN_PNS], urutan: ['tmtsk' => 'ASC']),
        ];
    }

    /**
     * Legacy: SK wajib saat tambah, dan saat ubah bila baris belum punya lampiran (`validate_param()` :464-468,
     * :500-505); 5 MB (:118), jpg|jpeg|png|gif|pdf (:126).
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SK_KP, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 20;
    }

    /**
     * - Pengangkatan CPNS/PNS hanya satu per pegawai (status selain 2/10) — legacy :473-483, :510-520.
     * - TMT tidak boleh kembar (status selain 2/10) — legacy :486-493, :523-530.
     * - [V2] TMT periode 1 April/1 Oktober (CR-025) bila tambah atau `tmtsk`/`id_jenis_kp` berubah.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $nip    = (string) $nilai[$this->kolomNip()];
        $jenis  = (int) $nilai['id_jenis_kp'];
        $errors = [];

        if (array_key_exists('id_jenis_kp', $data) && in_array($jenis, [JenisKp::PENGANGKATAN_CPNS, JenisKp::PENGANGKATAN_PNS], true)) {
            $ada = $this->barisAktif($nip, $lama)->where('id_jenis_kp', $jenis)->get()->getRowArray();

            if ($ada !== null) {
                $nama                  = $jenis === JenisKp::PENGANGKATAN_CPNS ? 'Pengangkatan CPNS' : 'Pengangkatan PNS';
                $errors['id_jenis_kp'] = ["Jenis KP {$nama} sudah terdaftar dengan TMT {$ada['tmtsk']}. Pilih jenis kenaikan pangkat lainnya."];
            }
        }

        if (array_key_exists('tmtsk', $data)) {
            if ($this->barisAktif($nip, $lama)->where('tmtsk', $data['tmtsk'])->countAllResults() > 0) {
                $errors['tmtsk'] = ['Riwayat KP dengan TMT SK ini sudah terdaftar. Gunakan TMT SK yang berbeda.'];
            }
        }

        $berubah = $lama === null
            || (array_key_exists('tmtsk', $data) && (string) $data['tmtsk'] !== (string) $lama['tmtsk'])
            || (array_key_exists('id_jenis_kp', $data) && (string) $data['id_jenis_kp'] !== (string) $lama['id_jenis_kp']);

        if ($berubah && ! isset($errors['tmtsk'])) {
            $hasil = KenaikanPangkat::validasiTmt((string) $nilai['tmtsk'], JenisKp::wajibTmtPeriode($jenis));

            if (! $hasil->valid) {
                $errors['tmtsk'] = [(string) $hasil->pesan];
            }
        }

        return $errors;
    }

    /**
     * Salin nama master (legacy `set_param()` :551-560): `jenis_kp` dari `jenis_kp`; `gol`, `ruang`, `gol_ruang`,
     * `pangkat` dari `pangkat`. Hanya bila rujukannya ada di payload.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $db = db_connect();

        if (array_key_exists('id_jenis_kp', $data)) {
            $data['jenis_kp'] = $db->table('jenis_kp')->select('jenis_kp')->where('id_jenis_kp', $data['id_jenis_kp'])->get()->getRow()->jenis_kp ?? null;
        }

        if (array_key_exists('id_pangkat', $data)) {
            $pangkat = $db->table('pangkat')->select('gol, ruang, gol_ruang, pangkat')->where('id_pangkat', $data['id_pangkat'])->get()->getRowArray();

            foreach (['gol', 'ruang', 'gol_ruang', 'pangkat'] as $kolom) {
                $data[$kolom] = $pangkat[$kolom] ?? null;
            }
        }

        return $data;
    }

    /**
     * Baris KP pegawai itu berstatus selain 2/10 (legacy `status NOT IN (2, 10)`), tanpa baris yang sedang diubah.
     *
     * @param array<string, mixed>|null $lama
     */
    private function barisAktif(string $nip, ?array $lama): BaseBuilder
    {
        $builder = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nip)
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if ($lama !== null) {
            $builder->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        return $builder;
    }
}
