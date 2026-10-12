<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;

/**
 * B-17 Riwayat Organisasi (WS-1 M4 MAKE-007), tanpa snapshot.
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Organisasi.php`, `libraries/hr/rwy/L_organisasi.php`
 * (`validate_param()` :424-505, `set_param()` :513-523, `list()` :27), form `views/hr/employee/rwy/organisasi/form.php`.
 * Hak akses: Matriks v2 `hr/rwy/organisasi/*` = 1, 2, 3, 7 (legacy index/detail UL_ALL — lihat ikut Matriks); proses
 * 1, 3 (legacy `process()`). `tgl_akhir` kosong = masih aktif (centang "Sampai Sekarang" legacy).
 */
final class Organisasi extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran sertifikat organisasi (konstanta legacy `ARSIP_RWY['organisasi']`).
     */
    public const LAMPIRAN_ORGANISASI = 13;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'organisasi';
    }

    public function label(): string
    {
        return 'Riwayat Organisasi';
    }

    public function tabel(): string
    {
        return 'riwayat_organisasi';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_organisasi';
    }

    public function fields(): array
    {
        return [
            new MasterField('nama_organisasi', 'Nama Organisasi', required: true, maxBytes: 255),
            new MasterField('kedudukan', 'Jabatan dalam Organisasi', required: true, maxBytes: 255),
            new MasterField('tgl_mulai', 'Tanggal Mulai', MasterField::TYPE_DATE, required: true),
            new MasterField('tgl_akhir', 'Tanggal Akhir', MasterField::TYPE_DATE, hint: 'Kosongkan bila masih aktif.'),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 65535),
        ];
    }

    public function izin(): array
    {
        $semua = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK];

        return [
            'lihat'  => $semua,
            'tambah' => $semua,
            'ubah'   => $semua,
            'hapus'  => $semua,
            'proses' => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::SelfService;
    }

    /**
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/organisasi/form.php:59-70`).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :27 `ORDER BY status ASC, tgl_mulai ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tgl_mulai' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    /**
     * Legacy: sertifikat wajib saat tambah, dan saat ubah bila belum ada; 5 MB, jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_ORGANISASI, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 75;
    }

    /**
     * Legacy `validate_param()` :454-500: tidak boleh kembar nama + tanggal mulai + tanggal akhir (keduanya kosong
     * dianggap sama) dengan riwayat pegawai itu berstatus selain 2/10 — atas nilai akhir baris (pola Kp, MAKE-005).
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai   = $data + ($lama ?? []);
        $builder = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nilai[$this->kolomNip()])
            ->where('nama_organisasi', $nilai['nama_organisasi'] ?? null)
            ->where('tgl_mulai', $nilai['tgl_mulai'] ?? null)
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if (($nilai['tgl_akhir'] ?? null) === null || $nilai['tgl_akhir'] === '') {
            $builder->where('tgl_akhir', null);
        } else {
            $builder->where('tgl_akhir', $nilai['tgl_akhir']);
        }

        if ($lama !== null) {
            $builder->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        return $builder->countAllResults() > 0
            ? ['nama_organisasi' => ['Riwayat organisasi dengan nama dan periode ini sudah terdaftar.']]
            : [];
    }
}
