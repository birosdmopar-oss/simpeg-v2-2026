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
 * B-11 Riwayat Diklat/Pelatihan (WS-1 M4 MAKE-007).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Diklat.php`, `libraries/hr/rwy/L_diklat.php`
 * (`validate_param()` :464-624, `set_param()` :626-685, `list()` :30), form `views/hr/employee/rwy/diklat/form.php`.
 * Snapshot `pegawai_diklat`: dok DBV-012 §5.1 — status 1, `jenis_diklat = 1` (struktural), `tgl_sertifikat` terbaru.
 * Hak akses: Matriks v2 `hr/rwy/diklat/*` = 1, 2, 3, 7 (legacy index UL_ALL — lihat ikut Matriks); proses 1, 3.
 *
 * Isian per jenis (legacy): 3 Fungsional → sub-group jabatan (group 2) + deskripsi; 5 Sertifikasi → nama, rumpun,
 * masa berlaku, lembaga (disimpan di `instansi_penyelenggara`); lainnya → `id_diklat` master per jenis (+ deskripsi untuk
 * jenis 2); jumlah JP dan instansi untuk selain 5. Kolom milik jenis lain dikosongkan (`set_param()`).
 *
 * [V2] (keputusan user 12-10-2026):
 * - Status mengikuti aturan engine (UL_PEGAWAI 0, admin 1, role 1 memilih status saat ubah). Legacy `set_param()` tidak
 *   pernah menyimpan `status` (bug: semua baris 0, pilihan role 1 diabaikan).
 * - Diklat terpilih wajib milik jenis terpilih (`diklat.jenis_diklat`) — lebih ketat dari legacy yang hanya memeriksa
 *   keberadaan `id_diklat` (`L_diklat.php:527-536`); dropdown legacy memang sudah disaring per jenis.
 * - Jenis 5: rumpun & lembaga sertifikasi diisi TEKS (`rumpun_sertifikasi`, `instansi_penyelenggara`), `id_rumpun_
 *   sertifikasi`/`id_lembaga_sertifikasi` NULL — master `rumpun_sertifikasi`/`lembaga_sertifikasi` belum ada di v2
 *   (kebutuhan skema, DBV baru).
 */
final class Diklat extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran sertifikat (konstanta legacy `ARSIP_RWY['diklat']`).
     */
    public const LAMPIRAN_SERTIFIKAT = 5;

    public const STRUKTURAL  = 1;
    public const TEKNIS      = 2;
    public const FUNGSIONAL  = 3;
    public const PRAJABATAN  = 4;
    public const SERTIFIKASI = 5;

    /**
     * Group jabatan fungsional — sumber pilihan sub-group jabatan jenis 3 (legacy `id_group_jabatan=2`).
     */
    public const GROUP_JABATAN_FUNGSIONAL = 2;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'diklat';
    }

    public function label(): string
    {
        return 'Riwayat Diklat';
    }

    public function tabel(): string
    {
        return 'riwayat_diklat';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_diklat';
    }

    public function fields(): array
    {
        return [
            new MasterField('jenis_diklat', 'Jenis Diklat', MasterField::TYPE_SELECT, required: true, options: [
                self::PRAJABATAN => 'Prajabatan', self::STRUKTURAL => 'Struktural', self::TEKNIS => 'Teknis',
                self::FUNGSIONAL => 'Fungsional', self::SERTIFIKASI => 'Sertifikasi',
            ]),
            new MasterField('id_diklat', 'Nama Diklat', MasterField::TYPE_REF, entity: 'diklat'),
            new MasterField('id_sub_group_jabatan', 'Jabatan Fungsional', MasterField::TYPE_REF, entity: 'sub-group-jabatan'),
            new MasterField('nama_diklat', 'Nama Sertifikasi', maxBytes: 255),
            new MasterField('rumpun_sertifikasi', 'Rumpun Sertifikasi', maxBytes: 255),
            new MasterField('deskripsi', 'Deskripsi', MasterField::TYPE_TEXTAREA, maxBytes: 255),
            new MasterField('no_sertifikat', 'Nomor Sertifikat', required: true, maxBytes: 255),
            new MasterField('tgl_sertifikat', 'Tanggal Sertifikat', MasterField::TYPE_DATE, required: true),
            new MasterField('mb_sertifikat_awal', 'Masa Berlaku Mulai', MasterField::TYPE_DATE),
            new MasterField('mb_sertifikat_akhir', 'Masa Berlaku Akhir', MasterField::TYPE_DATE),
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
     * Legacy form role 1 menawarkan pilihan status saat edit (`views/hr/employee/rwy/diklat/form.php:59-69`); [V2]
     * benar-benar disimpan (lihat docblock kelas).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :30 `ORDER BY status ASC, tgl_sertifikat ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tgl_sertifikat' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_diklat', [
                'id_riwayat_diklat', 'id_diklat', 'id_sub_group_jabatan', 'tgl_sertifikat', 'no_sertifikat', 'jumlah_jp',
                'nama_diklat', 'nama_diklat_lain', 'sub_group_jabatan', 'deskripsi', 'instansi_penyelenggara', 'keterangan',
                'id_rw_siasn',
            ], filter: ['jenis_diklat' => self::STRUKTURAL], urutan: ['tgl_sertifikat' => 'DESC']),
        ];
    }

    /**
     * Legacy: sertifikat wajib saat tambah, dan saat ubah bila belum ada; **1 MB** (`L_diklat.php`), jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SERTIFIKAT, true, 1, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 60;
    }

    /**
     * Legacy `validate_param()`: isian wajib per jenis; [V2] diklat milik jenisnya; sub-group jabatan di group fungsional;
     * duplikat hanya jenis 1 (nip + tanggal + diklat, status selain 2/10) — atas nilai akhir baris.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $jenis  = (int) ($nilai['jenis_diklat'] ?? 0);
        $db     = db_connect();
        $errors = [];
        $wajib  = static function (string $kolom, string $pesan) use ($nilai, &$errors): void {
            if (self::kosong($nilai[$kolom] ?? null)) {
                $errors[$kolom] = [$pesan];
            }
        };

        if ($jenis === self::FUNGSIONAL) {
            $wajib('id_sub_group_jabatan', 'Jabatan fungsional wajib dipilih.');
            $wajib('deskripsi', 'Deskripsi wajib diisi.');

            if (! isset($errors['id_sub_group_jabatan'])
                && $db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $nilai['id_sub_group_jabatan'])->where('id_group_jabatan', self::GROUP_JABATAN_FUNGSIONAL)->countAllResults() === 0) {
                $errors['id_sub_group_jabatan'] = ['Jabatan fungsional tidak valid.'];
            }
        } elseif ($jenis === self::SERTIFIKASI) {
            $wajib('nama_diklat', 'Nama sertifikasi wajib diisi.');
            $wajib('rumpun_sertifikasi', 'Rumpun sertifikasi wajib diisi.');
            $wajib('mb_sertifikat_awal', 'Masa berlaku mulai wajib diisi.');
            $wajib('mb_sertifikat_akhir', 'Masa berlaku akhir wajib diisi.');
        } else {
            $wajib('id_diklat', 'Nama diklat wajib dipilih.');

            if ($jenis === self::TEKNIS) {
                $wajib('deskripsi', 'Deskripsi wajib diisi.');
            }

            if (! isset($errors['id_diklat'])
                && $db->table('diklat')->where('id_diklat', $nilai['id_diklat'])->where('jenis_diklat', $jenis)->countAllResults() === 0) {
                $errors['id_diklat'] = ['Diklat tidak sesuai jenis diklat yang dipilih.'];
            }
        }

        if ($jenis !== self::SERTIFIKASI) {
            $wajib('jumlah_jp', 'Jumlah JP wajib diisi.');
        }

        if ($jenis === self::STRUKTURAL && ! isset($errors['id_diklat'])) {
            $kembar = $db->table($this->tabel())
                ->where($this->kolomNip(), $nilai[$this->kolomNip()])
                ->where('jenis_diklat', self::STRUKTURAL)
                ->where('tgl_sertifikat', $nilai['tgl_sertifikat'] ?? null)
                ->where('id_diklat', $nilai['id_diklat'])
                ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

            if ($lama !== null) {
                $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
            }

            if ($kembar->countAllResults() > 0) {
                $errors['tgl_sertifikat'] = ['Diklat struktural ini dengan tanggal sertifikat yang sama sudah terdaftar.'];
            }
        }

        return $errors;
    }

    /**
     * Legacy `set_param()`: kolom milik jenis lain dikosongkan; nama diklat/sub-group disalin dari master.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai = $data + ($lama ?? []);
        $jenis = (int) ($nilai['jenis_diklat'] ?? 0);
        $db    = db_connect();

        $data['id_rumpun_sertifikasi']  = null;
        $data['id_lembaga_sertifikasi'] = null;

        if ($jenis !== self::SERTIFIKASI) {
            foreach (['rumpun_sertifikasi', 'mb_sertifikat_awal', 'mb_sertifikat_akhir'] as $kolom) {
                $data[$kolom] = null;
            }
        } else {
            $data['jumlah_jp'] = null;
        }

        if ($jenis === self::FUNGSIONAL) {
            $data['id_diklat']         = null;
            $data['nama_diklat']       = null;
            $data['sub_group_jabatan'] = $db->table('sub_group_jabatan')->select('sub_group_jabatan')->where('id_sub_group_jabatan', $nilai['id_sub_group_jabatan'])->get()->getRow()->sub_group_jabatan ?? null;
        } else {
            $data['id_sub_group_jabatan'] = null;
            $data['sub_group_jabatan']    = null;

            if ($jenis === self::SERTIFIKASI) {
                $data['id_diklat'] = null;
            } else {
                $data['nama_diklat'] = $db->table('diklat')->select('nama_diklat')->where('id_diklat', $nilai['id_diklat'])->get()->getRow()->nama_diklat ?? null;
            }
        }

        return $data;
    }

    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || trim((string) $nilai) === '';
    }
}
