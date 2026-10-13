<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;

/**
 * B-17 Riwayat Tanda Jasa (WS-1 M4 MAKE-007).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Tj.php`, `libraries/hr/rwy/L_tj.php`
 * (`validate_param()` :418-489, `set_param()` :491-508, `list()` :27), form `views/hr/employee/rwy/tj/form.php`.
 * Snapshot `pegawai_tanda_jasa`: dok DBV-012 §5.1 — status 1 dan `id_tanda_jasa` 26/27/28 (Satyalancana Karya Satya),
 * `tgl_sertifikat` terbaru. Hak akses: Matriks v2 `hr/rwy/tj/*` = 1, 3 (Admin), 2, 4, 5 (View); legacy index juga
 * membuka 7, 8 — lihat ikut Matriks. Ubah/hapus/proses = legacy userAuth 1, 3; tambah hanya role 1 (tombol Tambah legacy
 * hanya untuk role 1, `Tj.php:64`; keputusan user 12-10-2026).
 */
final class TandaJasa extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran tanda jasa (konstanta legacy `ARSIP_RWY['tj']`).
     */
    public const LAMPIRAN_TANDA_JASA = 23;

    /**
     * Kode master `tanda_jasa` "LAIN-LAIN" (legacy hard-code :455, form.php:100): nama diisi `tanda_jasa_lain`.
     */
    public const TANDA_JASA_LAIN = 44;

    /**
     * Satyalancana Karya Satya — pengisi snapshot `pegawai_tanda_jasa` (trigger legacy `riwayat_tanda_jasa_af*`).
     *
     * @var list<int>
     */
    public const KARYA_SATYA = [26, 27, 28];

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'tanda-jasa';
    }

    public function label(): string
    {
        return 'Riwayat Tanda Jasa';
    }

    public function tabel(): string
    {
        return 'riwayat_tanda_jasa';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_tanda_jasa';
    }

    public function fields(): array
    {
        return [
            new MasterField('id_tanda_jasa', 'Tanda Jasa', MasterField::TYPE_REF, required: true, entity: 'tanda-jasa'),
            new MasterField('tanda_jasa_lain', 'Tanda Jasa Lainnya', maxBytes: 255),
            new MasterField('no_sertifikat', 'Nomor Sertifikat', required: true, maxBytes: 100),
            new MasterField('tgl_sertifikat', 'Tanggal Sertifikat', MasterField::TYPE_DATE, required: true),
            new MasterField('negara', 'Negara', maxBytes: 255),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $admin = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI],
            'tambah' => [Role::SUPER_ADMIN],
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
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/tj/form.php:59-69`).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :27 `ORDER BY status ASC, tgl_sertifikat ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tgl_sertifikat' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_tanda_jasa', [
                'id_riwayat_tanda_jasa', 'id_tanda_jasa', 'tgl_sertifikat', 'no_sertifikat', 'tanda_jasa', 'negara',
                'keterangan', 'id_rw_siasn',
            ], filter: ['id_tanda_jasa' => self::KARYA_SATYA], urutan: ['tgl_sertifikat' => 'DESC']),
        ];
    }

    /**
     * Legacy: sertifikat wajib saat tambah, dan saat ubah bila belum ada (:447, :466-473); 5 MB, jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_TANDA_JASA, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 70;
    }

    /**
     * Legacy: `tanda_jasa_lain` wajib untuk LAIN-LAIN (44); tanggal sertifikat tidak kembar (status selain 2/10) — atas
     * nilai akhir baris.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $errors = [];

        if ((int) ($nilai['id_tanda_jasa'] ?? 0) === self::TANDA_JASA_LAIN && trim((string) ($nilai['tanda_jasa_lain'] ?? '')) === '') {
            $errors['tanda_jasa_lain'] = ['Tanda jasa lainnya wajib diisi.'];
        }

        $kembar = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nilai[$this->kolomNip()])
            ->where('tgl_sertifikat', $nilai['tgl_sertifikat'] ?? null)
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if ($lama !== null) {
            $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        if ($kembar->countAllResults() > 0) {
            $errors['tgl_sertifikat'] = ['Riwayat tanda jasa dengan tanggal sertifikat ini sudah terdaftar.'];
        }

        return $errors;
    }

    /**
     * Legacy `set_param()`: nama dari master `tanda_jasa`; `tanda_jasa_lain` hanya untuk LAIN-LAIN.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai = $data + ($lama ?? []);

        if (array_key_exists('id_tanda_jasa', $data)) {
            $data['tanda_jasa'] = db_connect()->table('tanda_jasa')->select('tanda_jasa')->where('id_tanda_jasa', $data['id_tanda_jasa'])->get()->getRow()->tanda_jasa ?? null;
        }

        if ((int) ($nilai['id_tanda_jasa'] ?? 0) !== self::TANDA_JASA_LAIN) {
            $data['tanda_jasa_lain'] = null;
        }

        return $data;
    }
}
