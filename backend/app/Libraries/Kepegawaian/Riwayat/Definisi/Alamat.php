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
 * B-16 Riwayat Alamat (WS-1 M4 MAKE-007).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Alamat.php`, `libraries/hr/rwy/L_alamat.php` (`vp()`
 * :489-591, `sp()` :593-682, `list()` :30), form `views/hr/employee/rwy/alamat/form.php`. Snapshot multi-target dok
 * DBV-012 §5.1: `pegawai_alamat` (status 1, `alamat_utama = 1`, terbaru) dan `pegawai_alamat_kantor` (status 1,
 * `jenis_alamat = 3`, terbaru). Hak akses: Matriks v2 `hr/rwy/alamat/*` = 1, 2, 3, 6, 7 (legacy index/detail UL_ALL —
 * lihat ikut Matriks); proses 1, 3 (legacy `process()`).
 *
 * Wilayah berjenjang provinsi → kelurahan dengan sentinel LAIN-LAIN (pola master kantor, `KantorHooks`): level di bawah
 * LAIN-LAIN wajib LAIN-LAIN, pasangan riil satu rantai, isian `*_lain` wajib bila levelnya LAIN-LAIN dan NULL bila bukan.
 * [V2] `kd_pos` bila diisi 5 digit (snapshot `pegawai_alamat*.kd_pos` CHAR(5)) dan, bila kelurahan punya daftar kode pos,
 * salah satunya; legacy menerima teks bebas.
 */
final class Alamat extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran KTP (konstanta legacy `ARSIP_RWY['alamat']`).
     */
    public const LAMPIRAN_KTP = 1;

    public const JENIS_KTP      = 1;
    public const JENIS_DOMISILI = 2;
    public const JENIS_KANTOR   = 3;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * Level wilayah: kolom kode => [key master, kolom nama, kolom "lainnya"].
     */
    public const WILAYAH = [
        'id_provinsi'       => ['provinsi', 'provinsi', 'provinsi_lain'],
        'id_kabupaten_kota' => ['kabupaten-kota', 'kabupaten_kota', 'kabupaten_kota_lain'],
        'id_kecamatan'      => ['kecamatan', 'kecamatan', 'kecamatan_lain'],
        'id_kelurahan'      => ['kelurahan', 'kelurahan', 'kelurahan_lain'],
    ];

    /**
     * Isian khusus alamat kantor (jenis 3); NULL untuk jenis lain (legacy `sp()`).
     *
     * @var list<string>
     */
    public const KOLOM_KANTOR = ['nama_kantor', 'lantai', 'telp', 'faks'];

    private const KOLOM_WILAYAH_SNAPSHOT = [
        'id_riwayat_alamat', 'id_provinsi', 'id_kabupaten_kota', 'id_kecamatan', 'id_kelurahan', 'provinsi', 'provinsi_lain',
        'kabupaten_kota', 'kabupaten_kota_lain', 'kecamatan', 'kecamatan_lain', 'kelurahan', 'kelurahan_lain', 'kd_pos',
        'alamat', 'keterangan',
    ];

    public function jenis(): string
    {
        return 'alamat';
    }

    public function label(): string
    {
        return 'Riwayat Alamat';
    }

    public function tabel(): string
    {
        return 'riwayat_alamat';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_alamat';
    }

    public function fields(): array
    {
        return [
            new MasterField('jenis_alamat', 'Jenis Alamat', MasterField::TYPE_SELECT, required: true, options: [
                self::JENIS_KTP => 'Alamat KTP', self::JENIS_DOMISILI => 'Alamat Domisili', self::JENIS_KANTOR => 'Kantor',
            ]),
            new MasterField('alamat_utama', 'Alamat Utama', MasterField::TYPE_SELECT, options: [1 => 'Ya', 2 => 'Tidak']),
            new MasterField('id_provinsi', 'Provinsi', MasterField::TYPE_REF, required: true, entity: 'provinsi', allowSystem: true, checkDependsOn: false),
            new MasterField('provinsi_lain', 'Provinsi Lainnya', maxBytes: 255),
            new MasterField('id_kabupaten_kota', 'Kabupaten/Kota', MasterField::TYPE_REF, required: true, entity: 'kabupaten-kota', dependsOn: 'id_provinsi', allowSystem: true, checkDependsOn: false),
            new MasterField('kabupaten_kota_lain', 'Kabupaten/Kota Lainnya', maxBytes: 255),
            new MasterField('id_kecamatan', 'Kecamatan', MasterField::TYPE_REF, required: true, entity: 'kecamatan', dependsOn: 'id_kabupaten_kota', allowSystem: true, checkDependsOn: false),
            new MasterField('kecamatan_lain', 'Kecamatan Lainnya', maxBytes: 255),
            new MasterField('id_kelurahan', 'Kelurahan/Desa', MasterField::TYPE_REF, required: true, entity: 'kelurahan', dependsOn: 'id_kecamatan', allowSystem: true, checkDependsOn: false),
            new MasterField('kelurahan_lain', 'Kelurahan/Desa Lainnya', maxBytes: 255),
            new MasterField('kd_pos', 'Kode Pos', maxBytes: 5),
            new MasterField('alamat', 'Alamat', MasterField::TYPE_TEXTAREA, required: true, maxBytes: 255),
            new MasterField('nama_kantor', 'Nama Kantor', maxBytes: 255),
            new MasterField('lantai', 'Lantai', maxBytes: 50),
            new MasterField('telp', 'Telepon', maxBytes: 50),
            new MasterField('faks', 'Faks', maxBytes: 50),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $semua = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

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
     * CHECK `riwayat_alamat.status IN (0, 1, 2, 10)` di DDL: tanpa 3 walau flag Diproses aktif.
     */
    public function pemetaanStatus(): array
    {
        return [
            StatusRiwayat::Menunggu->value  => StatusRiwayat::Menunggu,
            StatusRiwayat::Disetujui->value => StatusRiwayat::Disetujui,
            StatusRiwayat::Ditolak->value   => StatusRiwayat::Ditolak,
            StatusRiwayat::Dihapus->value   => StatusRiwayat::Dihapus,
        ];
    }

    /**
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/alamat/form.php:59-67`, pola KP/Pendidikan).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :30 `ORDER BY status ASC, id_riwayat_alamat ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        $terbaru = ['created_at' => 'DESC', $this->primaryKey() => 'DESC'];

        return [
            new AturanSnapshot('pegawai_alamat', [...self::KOLOM_WILAYAH_SNAPSHOT, 'alamat_utama', 'jenis_alamat'], filter: ['alamat_utama' => 1], urutan: $terbaru),
            new AturanSnapshot('pegawai_alamat_kantor', [...self::KOLOM_WILAYAH_SNAPSHOT, ...self::KOLOM_KANTOR], filter: ['jenis_alamat' => self::JENIS_KANTOR], urutan: $terbaru),
        ];
    }

    /**
     * Legacy: KTP wajib kecuali alamat kantor, saat tambah dan saat ubah bila belum ada (`vp()` :572-588); 5 MB,
     * jpg|jpeg|png|gif|pdf. Wajib bersyarat lewat lampiranWajib().
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_KTP, true, 5, self::EKSTENSI)];
    }

    public function lampiranWajib(AturanLampiran $aturan, array $nilai): bool
    {
        return $aturan->wajib && (int) ($nilai['jenis_alamat'] ?? 0) !== self::JENIS_KANTOR;
    }

    public function urutanTab(): int
    {
        return 55;
    }

    /**
     * Legacy `vp()`: alamat utama wajib kecuali kantor; rantai wilayah + LAIN-LAIN; [V2] kode pos 5 digit.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai    = $data + ($lama ?? []);
        $errors   = [];
        $registry = service('masterRegistry');

        if ((int) ($nilai['jenis_alamat'] ?? 0) !== self::JENIS_KANTOR && self::kosong($nilai['alamat_utama'] ?? null)) {
            $errors['alamat_utama'] = ['Alamat utama wajib dipilih.'];
        }

        $sistemDiAtas = null;
        $induk        = null;
        $labelInduk   = '';
        $kelurahan    = null;

        foreach (self::WILAYAH as $kolom => [$entity, , $kolomLain]) {
            $def    = $registry->get($entity);
            $label  = $this->labelField($kolom);
            $kode   = trim((string) ($nilai[$kolom] ?? ''));
            $sistem = $kode !== '' && $def->isSystemId($kode);

            if ($sistemDiAtas !== null && ! $sistem) {
                $errors[$kolom] = ["{$label} harus LAIN-LAIN bila {$sistemDiAtas} LAIN-LAIN."];
            } elseif ($sistem) {
                $sistemDiAtas ??= $label;

                if (self::kosong($nilai[$kolomLain] ?? null)) {
                    $errors[$kolomLain] = ["{$this->labelField($kolomLain)} wajib diisi bila {$label} LAIN-LAIN."];
                }
            } elseif ($kode !== '' && $induk !== null && $def->parentField !== null) {
                $baris = db_connect()->table($def->table)->where($def->primaryKey, $kode)->get()->getRowArray();

                if ($baris !== null && (string) $baris[$def->parentField] !== $induk) {
                    $errors[$kolom] = ["{$label} tidak berada di bawah {$labelInduk} yang dipilih."];
                }

                $kelurahan = $kolom === 'id_kelurahan' ? $baris : $kelurahan;
            } elseif ($kode !== '' && $kolom === 'id_kelurahan') {
                $kelurahan = db_connect()->table($def->table)->where($def->primaryKey, $kode)->get()->getRowArray();
            }

            $induk      = $sistem || $kode === '' ? null : $kode;
            $labelInduk = $label;
        }

        $kdPos = trim((string) ($nilai['kd_pos'] ?? ''));

        if ($kdPos !== '') {
            if (preg_match('/^[0-9]{5}\z/', $kdPos) !== 1) {
                $errors['kd_pos'] = ['Kode Pos harus 5 digit angka.'];
            } elseif ($kelurahan !== null) {
                $daftar = array_values(array_filter(array_map('trim', explode(',', (string) ($kelurahan['kd_pos'] ?? ''))), static fn (string $v): bool => $v !== ''));

                if ($daftar !== [] && ! in_array($kdPos, $daftar, true)) {
                    $errors['kd_pos'] = ['Kode Pos harus salah satu kode pos kelurahan terpilih: ' . implode(', ', $daftar) . '.'];
                }
            }
        }

        return $errors;
    }

    /**
     * Legacy `sp()`: salin nama wilayah dari master, `*_lain` hanya untuk LAIN-LAIN, `KdPos` = `kd_pos`; kantor →
     * alamat utama 2, selain kantor → isian kantor NULL. Dihitung dari nilai akhir baris.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai    = $data + ($lama ?? []);
        $registry = service('masterRegistry');
        $db       = db_connect();

        foreach (self::WILAYAH as $kolom => [$entity, $kolomNama, $kolomLain]) {
            $def  = $registry->get($entity);
            $kode = trim((string) ($nilai[$kolom] ?? ''));

            $data[$kolomNama] = $kode === '' ? null
                : ($db->table($def->table)->select($def->nameField)->where($def->primaryKey, $kode)->get()->getRowArray()[$def->nameField] ?? null);
            $data[$kolomLain] = $kode !== '' && $def->isSystemId($kode) ? ($nilai[$kolomLain] ?? null) : null;
        }

        $data['KdPos'] = self::kosong($nilai['kd_pos'] ?? null) ? null : $nilai['kd_pos'];

        if ((int) ($nilai['jenis_alamat'] ?? 0) === self::JENIS_KANTOR) {
            $data['alamat_utama'] = 2;
        } else {
            foreach (self::KOLOM_KANTOR as $kolom) {
                $data[$kolom] = null;
            }
        }

        return $data;
    }

    private function labelField(string $nama): string
    {
        foreach ($this->fields() as $field) {
            if ($field->name === $nama) {
                return $field->label;
            }
        }

        return $nama;
    }

    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || trim((string) $nilai) === '';
    }
}
