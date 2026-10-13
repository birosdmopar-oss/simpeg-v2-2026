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
 * B-11 Riwayat Kursus/Seminar (WS-1 M4 MAKE-007), tanpa snapshot.
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Seminar.php`, `libraries/hr/rwy/L_seminar.php`
 * (`validate_param()` :419-512, `set_param()` :514-532, `list()` :27), form `views/hr/employee/rwy/seminar/form.php`.
 * Hak akses: Matriks v2 `hr/rwy/seminar/*` = 1, 2, 3, 7 (legacy index UL_ALL — lihat ikut Matriks); proses 1, 3.
 *
 * `bidang_seminar` dan `instansi_penyelenggara` disimpan sebagai TEKS (kolom tanpa FK): legacy memilih nama dari master
 * `bidang_kursem`/`instansi_kursem` atau "Lain-lain" teks bebas; v2 menerima teks (frontend menyarankan nama master).
 */
final class Seminar extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran sertifikat (konstanta legacy `ARSIP_RWY['seminar']`).
     */
    public const LAMPIRAN_SERTIFIKAT = 22;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'seminar';
    }

    public function label(): string
    {
        return 'Riwayat Kursus/Seminar';
    }

    public function tabel(): string
    {
        return 'riwayat_seminar';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_seminar';
    }

    public function fields(): array
    {
        return [
            new MasterField('jenis_seminar', 'Jenis', MasterField::TYPE_SELECT, required: true, options: [1 => 'Seminar', 2 => 'Kursus']),
            new MasterField('bidang_seminar', 'Bidang', required: true, maxBytes: 100),
            new MasterField('nama_seminar', 'Nama Kursus/Seminar', required: true, maxBytes: 255),
            new MasterField('no_sertifikat', 'Nomor Sertifikat', required: true, maxBytes: 100),
            new MasterField('tgl_sertifikat', 'Tanggal Sertifikat', MasterField::TYPE_DATE, required: true),
            new MasterField('jumlah_jp', 'Jumlah JP', MasterField::TYPE_INT, columnType: 'int', min: 0),
            new MasterField('instansi_penyelenggara', 'Instansi Penyelenggara', required: true, maxBytes: 255),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
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
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/seminar/form.php:59-69`).
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

    /**
     * Legacy: sertifikat wajib saat tambah, dan saat ubah bila belum ada; **1 MB** (`L_seminar.php`), jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SERTIFIKAT, true, 1, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 65;
    }

    /**
     * Legacy :473-505: nama + tanggal sertifikat tidak kembar (status selain 2/10) — atas nilai akhir baris.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $kembar = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nilai[$this->kolomNip()])
            ->where('nama_seminar', trim((string) ($nilai['nama_seminar'] ?? '')))
            ->where('tgl_sertifikat', $nilai['tgl_sertifikat'] ?? null)
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if ($lama !== null) {
            $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        return $kembar->countAllResults() > 0
            ? ['nama_seminar' => ['Riwayat kursus/seminar dengan nama dan tanggal sertifikat ini sudah terdaftar.']]
            : [];
    }
}
