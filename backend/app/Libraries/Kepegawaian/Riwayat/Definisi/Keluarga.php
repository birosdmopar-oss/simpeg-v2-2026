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
 * B-16 Riwayat Keluarga — perkawinan + anak (WS-1 M4 MAKE-007).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Keluarga.php`, `libraries/hr/rwy/L_keluarga.php`
 * (`vp()` :958-1033, `sp()` :1035-1053, `vp_anak()`/`sp_anak()` :1055-1193, `list()` :41-45), form
 * `views/hr/employee/rwy/keluarga/form.php`. Snapshot `pegawai_keluarga`: dok DBV-012 §5.1 (status 1,
 * `tgl_perkawinan` terbaru). Hak akses: Matriks v2 `hr/rwy/keluarga/*` = 1, 2, 3, 7 (legacy index juga 4, 5, 8 — lihat
 * ikut Matriks); proses 1, 3.
 *
 * Anak = tabel `detail_anak` (FK `id_riwayat_keluarga`), dikelola sub-resource `riwayat/keluarga/{id}/anak` (service
 * `AnakKeluarga`, keputusan reviewer CR 12-10-2026). `jumlah_anak` dihitung dari baris anak (legacy `sp()`), bukan input.
 * Lampiran: buku nikah kode 20 per baris keluarga; akte lahir kode 3 per baris `detail_anak` (`entriLampiran()`).
 */
final class Keluarga extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` (konstanta legacy `ARSIP_RWY['keluarga']`, `ARSIP_RWY['anak']`).
     */
    public const LAMPIRAN_BUKU_NIKAH = 20;

    public const LAMPIRAN_AKTE_ANAK = 3;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'keluarga';
    }

    public function label(): string
    {
        return 'Riwayat Keluarga';
    }

    public function tabel(): string
    {
        return 'riwayat_keluarga';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_keluarga';
    }

    public function fields(): array
    {
        return [
            new MasterField('urutan_perkawinan', 'Perkawinan Ke-', MasterField::TYPE_INT, required: true, columnType: 'tinyint', min: 1),
            new MasterField('tgl_perkawinan', 'Tanggal Perkawinan', MasterField::TYPE_DATE, required: true),
            new MasterField('kota_perkawinan', 'Kota Perkawinan', required: true, maxBytes: 255),
            new MasterField('nama_pasangan', 'Nama Pasangan', required: true, maxBytes: 255),
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
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/keluarga/form.php:59-72`).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :41 `ORDER BY status ASC, tgl_perkawinan ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tgl_perkawinan' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_keluarga', [
                'id_riwayat_keluarga', 'urutan_perkawinan', 'tgl_perkawinan', 'kota_perkawinan', 'nama_pasangan', 'jumlah_anak',
                'keterangan',
            ], urutan: ['tgl_perkawinan' => 'DESC']),
        ];
    }

    /**
     * Legacy: buku nikah wajib saat tambah, dan saat ubah bila belum ada; akte lahir wajib per anak; 5 MB,
     * jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [
            new AturanLampiran(self::LAMPIRAN_BUKU_NIKAH, true, 5, self::EKSTENSI),
            new AturanLampiran(self::LAMPIRAN_AKTE_ANAK, true, 5, self::EKSTENSI),
        ];
    }

    public function entriLampiran(int $kode): ?array
    {
        return $kode === self::LAMPIRAN_AKTE_ANAK
            ? ['tabel' => 'detail_anak', 'pk' => 'id_detail_anak', 'induk' => $this->primaryKey()]
            : null;
    }

    public function urutanTab(): int
    {
        return 50;
    }

    /**
     * Legacy `vp()` :997, :1021: tanggal perkawinan tidak kembar (status selain 2/10) — atas nilai akhir baris.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $kembar = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nilai[$this->kolomNip()])
            ->where('tgl_perkawinan', $nilai['tgl_perkawinan'] ?? null)
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if ($lama !== null) {
            $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        return $kembar->countAllResults() > 0
            ? ['tgl_perkawinan' => ['Riwayat keluarga dengan tanggal perkawinan ini sudah terdaftar.']]
            : [];
    }

    /**
     * `jumlah_anak` = jumlah baris `detail_anak` (legacy `sp()`); baris baru = 0.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $data['jumlah_anak'] = $lama === null ? 0 : self::hitungAnak((int) $lama[$this->primaryKey()]);

        return $data;
    }

    public static function hitungAnak(int $idKeluarga): int
    {
        return db_connect()->table('detail_anak')->where('id_riwayat_keluarga', $idKeluarga)->countAllResults();
    }
}
