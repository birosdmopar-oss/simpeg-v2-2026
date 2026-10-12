<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\JenisKp;
use App\Libraries\Kepegawaian\Kalkulasi\JenisReferensiTmt;
use App\Libraries\Kepegawaian\Kalkulasi\KenaikanGajiBerkala;
use App\Libraries\Kepegawaian\Kalkulasi\ReferensiTmt;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\BaseBuilder;

/**
 * B-09 Riwayat Kenaikan Gaji Berkala (WS-1 M3 MAKE-006).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Kgb.php`, `libraries/hr/rwy/L_kgb.php` (`vp()`
 * :429-506, `sp()` :508-529, `list()` :27-46, unggah :118-131), form `views/hr/employee/rwy/kgb/form.php`. Snapshot
 * `pegawai_kgb`: dok DBV-012 §5.1 (status 1, `MAX(tmtsk)`). Hak akses: Matriks v2 `hr/rwy/kgb/*` = 1, 3 (Admin),
 * 2, 4, 5 (View); legacy `index()` juga membuka role 7, 8 — tidak dibawa (Matriks v2, keputusan #4).
 *
 * [V2] SRS-FR-026 (CR-025, DoD B-09): TMT KGB minimal 2 tahun dari acuan pendahulu — KGB/KP terakhir (keputusan #10,
 * menutup CR-025 #3), KP pengangkatan CPNS dihitung sebagai TMT CPNS (`JenisReferensiTmt`). Hanya untuk input pengguna:
 * tambah, atau ubah yang mengubah `tmtsk` (K-CR025-10). Legacy tidak memvalidasinya.
 */
final class Kgb extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran SK KGB (konstanta legacy `ARSIP_RWY['kgb']`, `config/constants.php:199`).
     */
    public const LAMPIRAN_SK_KGB = 10;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'kgb';
    }

    public function label(): string
    {
        return 'Riwayat KGB';
    }

    public function tabel(): string
    {
        return 'riwayat_kgb';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_kgb';
    }

    /**
     * Field form legacy (`kgb/form.php`); wajib = `vp()` :441-460 (masa kerja dan keterangan opsional). Kolom PPPK
     * (`id_gol_pppk`/`gol_pppk`) tidak ada di form legacy — hanya diisi tarikan SIASN, tidak dibuka di sini.
     */
    public function fields(): array
    {
        return [
            new MasterField('tmtsk', 'TMT SK', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk', 'Nomor SK', required: true, maxBytes: 100),
            new MasterField('tgl_sk', 'Tanggal SK', MasterField::TYPE_DATE, required: true),
            new MasterField('mker_gol_th', 'Masa Kerja Golongan (Tahun)', MasterField::TYPE_INT, columnType: 'tinyint', min: 0),
            new MasterField('gaji_pokok', 'Gaji Pokok', MasterField::TYPE_INT, required: true, columnType: 'int', min: 0),
            new MasterField('jym', 'Jabatan yang Menandatangani', required: true, maxBytes: 255),
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
     * Legacy: role 1 memilih status saat edit KGB (`kgb/form.php:59-74`, pola sama dengan KP — keputusan user
     * 09-10-2026 untuk KP ikut legacy).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :30 `ORDER BY tmtsk ASC` (legacy hanya status 0/1; kontrak v2 semua kecuali 10).
     */
    public function urutanDaftar(): array
    {
        return ['tmtsk' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_kgb', [
                'id_riwayat_kgb', 'id_gol_pppk', 'tmtsk', 'no_sk', 'tgl_sk', 'gol_pppk', 'mker_gol_th', 'gaji_pokok', 'jym',
                'keterangan',
            ], urutan: ['tmtsk' => 'DESC']),
        ];
    }

    /**
     * Legacy: SK wajib saat tambah, dan saat ubah bila baris belum punya lampiran (`vp()` :463-468, :481-489); 5 MB
     * (:122), jpg|jpeg|png|gif|pdf (:130).
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SK_KGB, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 30;
    }

    /**
     * - TMT tidak boleh kembar (status selain 2/10) — legacy `vp()` :471-478, :492-499.
     * - [V2] Jarak minimal 2 tahun dari KGB/KP terakhir (CR-025) bila tambah atau `tmtsk` berubah.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai = $data + ($lama ?? []);
        $nip   = (string) $nilai[$this->kolomNip()];
        $tmt   = (string) ($nilai['tmtsk'] ?? '');

        // Cek duplikat atas NILAI AKHIR setiap tambah/ubah (pola Kp, MAKE-005), juga PUT parsial tanpa tmtsk — mis. baris
        // ditolak yang diaktifkan kembali lewat pilihan status role 1.
        if ($tmt !== '' && $this->barisKgbAktif($nip, $lama)->where('tmtsk', $tmt)->countAllResults() > 0) {
            return ['tmtsk' => ['Riwayat KGB dengan TMT SK ini sudah terdaftar. Gunakan TMT SK yang berbeda.']];
        }

        // K-CR025-10: jarak hanya untuk input pengguna — tambah, atau ubah yang mengubah tmtsk.
        if ($lama !== null && (! array_key_exists('tmtsk', $data) || $tmt === (string) $lama['tmtsk'])) {
            return [];
        }

        $hasil = KenaikanGajiBerkala::validasiJarak($tmt, $this->referensi($nip, $lama));

        return $hasil->valid ? [] : ['tmtsk' => [(string) $hasil->pesan]];
    }

    /**
     * Legacy `sp()` :516: masa kerja 0 disimpan NULL (`intval(...) > 0 ? ... : NULL`).
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        if (array_key_exists('mker_gol_th', $data) && $data['mker_gol_th'] !== null && (int) $data['mker_gol_th'] === 0) {
            $data['mker_gol_th'] = null;
        }

        return $data;
    }

    /**
     * Acuan jarak KGB (keputusan #10): TMT KGB lain dan TMT KP pegawai itu, status selain 2/10 (pola
     * `ReferensiTmt`), tanpa baris yang sedang diubah. KP pengangkatan CPNS = acuan TMT CPNS.
     *
     * @param array<string, mixed>|null $lama
     *
     * @return list<ReferensiTmt>
     */
    private function referensi(string $nip, ?array $lama): array
    {
        $referensi = [];

        foreach ($this->barisKgbAktif($nip, $lama)->select('tmtsk')->get()->getResultArray() as $row) {
            $referensi[] = new ReferensiTmt(JenisReferensiTmt::Kgb, (string) $row['tmtsk']);
        }

        $kp = db_connect()->table('riwayat_kp')
            ->select('id_jenis_kp, tmtsk')
            ->where('nip', $nip)
            ->whereNotIn('status', [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value])
            ->get()->getResultArray();

        foreach ($kp as $row) {
            $jenis       = (int) $row['id_jenis_kp'] === JenisKp::PENGANGKATAN_CPNS ? JenisReferensiTmt::Cpns : JenisReferensiTmt::Kp;
            $referensi[] = new ReferensiTmt($jenis, (string) $row['tmtsk']);
        }

        return $referensi;
    }

    /**
     * Baris KGB pegawai itu berstatus selain 2/10 (legacy `status NOT IN ('2', '10')`), tanpa baris yang sedang diubah.
     *
     * @param array<string, mixed>|null $lama
     */
    private function barisKgbAktif(string $nip, ?array $lama): BaseBuilder
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
