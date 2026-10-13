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
use CodeIgniter\Database\BaseConnection;

/**
 * B-07 Riwayat Jabatan / Mutasi (WS-2 M4 MAKE-012) di atas engine-v1 (tanpa hook baru).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Jabatan.php`, `libraries/hr/rwy/L_jabatan.php`
 * (`list()` :41-77, `insert()` :980, `update()` :1335, `validate_param()` :1890-2083, `set_param()` :2085-2330),
 * `views/hr/employee/rwy/jabatan/form.php`. Snapshot `pegawai_mutasi_jabatan`: dok DBV-012 §5.1
 * (`riwayat_mutasi_jabatan_af*`: status 1 AND `jenis_jabatan != 3`, `ORDER BY tmtsk DESC`, seri `>=` → baris yang
 * ditulis belakangan menang). Hak akses (keputusan user 13-10-2026, pola biasa): LIHAT ikut Matriks v2
 * `hr/rwy/jabatan/*` = 1, 2, 3, 4, 5; TULIS/PROSES ikut legacy [K] `controllers/hr/rwy/Jabatan.php` — add userAuth ['1']
 * (:83), edit ['1', '3'] (:178), delete ['1'] (:387), process ['1', '3'] (:579).
 *
 * Jenis jabatan (`jenis_jabatan`): 1 Nomenklatur, 2 Instansi Lain (isian teks bebas), 3 Plt (tidak masuk snapshot).
 * Nama master G-02 disalin server (beforeSave) — klien tidak bisa menimpa kolom nama untuk jenis 1/3.
 */
final class Jabatan extends RiwayatDefinisi
{
    public const LAMPIRAN_SK         = 9;
    public const LAMPIRAN_PS_JFT     = 36;
    public const LAMPIRAN_PERJANJIAN = 41;

    public const JENIS_NOMENKLATUR   = 1;
    public const JENIS_INSTANSI_LAIN = 2;
    public const JENIS_PLT           = 3;

    /**
     * Group jabatan JFT / JFU (legacy `validate_param()` :1951-1971).
     */
    public const GROUP_JFT = 2;
    public const GROUP_JFU = 3;

    /**
     * Satker "dipekerjakan keluar instansi": jabatan diisi teks bebas (legacy :1985-1990, set_param :2263-2268).
     */
    public const SATKER_DIPEKERJAKAN = 39;

    /**
     * Sub group dosen: berkas sertifikat dosen (legacy insert :1086).
     */
    public const SUB_GROUP_DOSEN = 12;

    /**
     * `id_jenis_pegawai` PPPK (legacy `JP_PPPK`, `config/constants.php:301`).
     */
    public const JENIS_PEGAWAI_PPPK = 6;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * Kolom `riwayat_mutasi_jabatan` yang disalin ke `pegawai_mutasi_jabatan` (semua kolom snapshot selain nip &
     * updated_at).
     *
     * @var list<string>
     */
    private const KOLOM_SNAPSHOT = [
        'id_riwayat_mutasi_jabatan', 'id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_atasan_es_1',
        'id_atasan_es_2', 'id_atasan_es_3', 'id_atasan_es_3_koord', 'id_atasan_es_4', 'id_atasan_es_4_koord', 'id_jabatan',
        'id_jabatan_koord', 'id_gol_pppk', 'id_rumpun_jabatan', 'jenis_jabatan', 'jenis_jabatan_koord', 'jenis_mutasi',
        'tmtsk', 'no_sk', 'tgl_sk', 'pmk', 'mhpk_mulai', 'mhpk_akhir', 'gol_pppk', 'nama_instansi', 'group_jabatan',
        'sub_group_jabatan', 'unit', 'satker', 'atasan_es_1', 'atasan_es_2', 'atasan_es_3', 'atasan_es_3_koord',
        'atasan_es_4', 'atasan_es_4_koord', 'jabatan', 'jabatan_koord', 'jabatan_lain', 'rumpun_jabatan',
        'subrumpun_jabatan', 'kelas_jabatan', 'kredit_jft', 'no_induk_jft', 'status_jft', 'ser_dosen', 'f_spmt', 'f_bapel',
        'keterangan', 'id_rw_siasn',
    ];

    /**
     * Kolom rujukan `jabatan_koordinasi` (tanpa entity master; diperiksa di validate()).
     *
     * @var list<string>
     */
    private const KOLOM_KOORDINASI = ['id_jabatan_koord', 'id_atasan_es_3_koord', 'id_atasan_es_4_koord'];

    /**
     * Kolom nomenklatur yang dikosongkan untuk jenis Instansi Lain (jenis 2).
     *
     * @var list<string>
     */
    private const KOLOM_NOMENKLATUR = [
        'id_group_jabatan', 'group_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_unit', 'id_satker',
        'id_atasan_es_1', 'atasan_es_1', 'id_atasan_es_2', 'atasan_es_2', 'id_atasan_es_3', 'atasan_es_3',
        'id_atasan_es_3_koord', 'atasan_es_3_koord', 'id_atasan_es_4', 'atasan_es_4', 'id_atasan_es_4_koord',
        'atasan_es_4_koord', 'id_jabatan', 'id_jabatan_koord', 'jabatan_koord', 'jabatan_lain', 'kelas_jabatan',
        'no_induk_jft', 'status_jft', 'id_gol_pppk', 'gol_pppk', 'pmk', 'mhpk_mulai', 'mhpk_akhir',
    ];

    public function jenis(): string
    {
        return 'jabatan';
    }

    public function label(): string
    {
        return 'Riwayat Jabatan';
    }

    public function tabel(): string
    {
        return 'riwayat_mutasi_jabatan';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_mutasi_jabatan';
    }

    /**
     * Isian form legacy (`form.php`). Wajib bersyarat (jenis jabatan, group JFT/JFU, satker 39, PPPK) diperiksa di
     * validate(); kolom nama master diturunkan beforeSave().
     */
    public function fields(): array
    {
        return [
            new MasterField('jenis_jabatan', 'Jenis Jabatan', MasterField::TYPE_SELECT, required: true, options: [
                self::JENIS_NOMENKLATUR => 'Nomenklatur', self::JENIS_INSTANSI_LAIN => 'Instansi Lain', self::JENIS_PLT => 'Plt',
            ]),
            new MasterField('jenis_mutasi', 'Jenis Mutasi', MasterField::TYPE_SELECT, options: [1 => 'Mutasi Jabatan', 2 => 'Mutasi Unor']),
            new MasterField('tmtsk', 'TMT SK', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk', 'Nomor SK', required: true, maxBytes: 100),
            new MasterField('tgl_sk', 'Tanggal SK', MasterField::TYPE_DATE, required: true),
            new MasterField('id_gol_pppk', 'Golongan PPPK', MasterField::TYPE_REF, entity: 'gol-pppk'),
            new MasterField('pmk', 'Tahun Perjanjian Masa Kerja', MasterField::TYPE_INT, min: 1901, max: 2155),
            new MasterField('mhpk_mulai', 'Mulai Masa Perjanjian Kerja', MasterField::TYPE_DATE),
            new MasterField('mhpk_akhir', 'Akhir Masa Perjanjian Kerja', MasterField::TYPE_DATE),
            new MasterField('id_group_jabatan', 'Group Jabatan', MasterField::TYPE_REF, entity: 'group-jabatan'),
            new MasterField('id_sub_group_jabatan', 'Sub Group Jabatan', MasterField::TYPE_REF, entity: 'sub-group-jabatan', dependsOn: 'id_group_jabatan'),
            new MasterField('jenis_jabatan_koord', 'Tugas Koordinasi', MasterField::TYPE_SELECT, options: [1 => 'Koordinator', 2 => 'Subkoordinator', 3 => 'Tidak Ada']),
            new MasterField('id_unit', 'Unit Kerja', MasterField::TYPE_REF, entity: 'unit'),
            new MasterField('id_satker', 'Satuan Kerja', MasterField::TYPE_REF, entity: 'satker', dependsOn: 'id_unit'),
            new MasterField('id_atasan_es_1', 'Atasan Eselon I', MasterField::TYPE_REF, entity: 'jabatan'),
            new MasterField('id_atasan_es_2', 'Atasan Eselon II', MasterField::TYPE_REF, entity: 'jabatan'),
            new MasterField('id_atasan_es_3', 'Atasan Eselon III', MasterField::TYPE_REF, entity: 'jabatan'),
            new MasterField('id_atasan_es_3_koord', 'Atasan Koordinator', MasterField::TYPE_INT, min: 1, max: 2147483647),
            new MasterField('id_atasan_es_4', 'Atasan Eselon IV', MasterField::TYPE_REF, entity: 'jabatan'),
            new MasterField('id_atasan_es_4_koord', 'Atasan Subkoordinator', MasterField::TYPE_INT, min: 1, max: 2147483647),
            new MasterField('id_rumpun_jabatan', 'Rumpun Jabatan', MasterField::TYPE_REF, entity: 'rumpun-jabatan'),
            new MasterField('id_jabatan', 'Jabatan', MasterField::TYPE_REF, entity: 'jabatan'),
            new MasterField('id_jabatan_koord', 'Jabatan Koordinasi', MasterField::TYPE_INT, min: 1, max: 2147483647),
            new MasterField('jabatan_lain', 'Jabatan Lain', maxBytes: 255),
            new MasterField('no_induk_jft', 'No. Induk JFT', maxBytes: 50),
            new MasterField('status_jft', 'Status JFT', MasterField::TYPE_SELECT, options: [1 => 'Aktif', 2 => 'Pembebasan Sementara']),
            new MasterField('nama_instansi', 'Kementerian / Lembaga', maxBytes: 255),
            new MasterField('unit', 'Unit Kerja (Instansi Lain)', maxBytes: 255),
            new MasterField('satker', 'Satuan Kerja (Instansi Lain)', maxBytes: 255),
            new MasterField('jabatan', 'Nama Jabatan', maxBytes: 255),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 65535),
        ];
    }

    public function izin(): array
    {
        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI],
            'tambah' => [Role::SUPER_ADMIN],
            'ubah'   => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
            'hapus'  => [Role::SUPER_ADMIN],
            'proses' => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    /**
     * Legacy `form.php:59-74`: role 1 memilih status saat edit (pola sama dengan KP/KGB).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :44 `ORDER BY status ASC, tmtsk ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tmtsk' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    /**
     * DBV-012 §5.1: status 1, `jenis_jabatan != 3`, `tmtsk DESC`. Seri `>=` legacy (trigger afIns/afUpd mengganti
     * snapshot bila tmtsk baris yang DITULIS >= tmtsk snapshot) → `updated_at DESC` sebelum pemecah seri PK: baris
     * bertanggal sama yang terakhir ditulis/disetujui menang. `notif_date` sengaja tidak dipakai: baris yang ditambah
     * langsung oleh admin (status 1) tidak punya notif_date sehingga akan kalah dari baris lama yang disetujui lewat
     * proses — berlawanan dengan legacy.
     */
    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_mutasi_jabatan', self::KOLOM_SNAPSHOT, filter: ['jenis_jabatan !=' => self::JENIS_PLT], urutan: ['tmtsk' => 'DESC', 'updated_at' => 'DESC']),
        ];
    }

    /**
     * Legacy insert/update :1003-1010: 5 MB, jpg|jpeg|png|gif|pdf. SK wajib (saat tambah, dan saat ubah bila belum
     * ada); SK pembebasan sementara JFT wajib bersyarat (lampiranWajib); perjanjian kerja opsional.
     */
    public function lampiran(): array
    {
        return [
            new AturanLampiran(self::LAMPIRAN_SK, true, 5, self::EKSTENSI),
            new AturanLampiran(self::LAMPIRAN_PS_JFT, true, 5, self::EKSTENSI),
            new AturanLampiran(self::LAMPIRAN_PERJANJIAN, false, 5, self::EKSTENSI),
        ];
    }

    /**
     * Legacy `validate_param()` :2034-2041, :2064-2073: SK PS JFT wajib bila jenis ≠ 2, group JFT, status JFT 2.
     */
    public function lampiranWajib(AturanLampiran $aturan, array $nilai): bool
    {
        if ($aturan->idRiwayat === self::LAMPIRAN_PS_JFT) {
            return (int) ($nilai['jenis_jabatan'] ?? 0) !== self::JENIS_INSTANSI_LAIN
                && (int) ($nilai['id_group_jabatan'] ?? 0) === self::GROUP_JFT
                && (int) ($nilai['status_jft'] ?? 0) === 2;
        }

        return $aturan->wajib;
    }

    /**
     * Berkas di kolom tabel (legacy insert :1086-1238): sertifikat dosen, SPMT, BA pelantikan — opsional, 5 MB.
     */
    public function kolomBerkas(): array
    {
        $aturan = new AturanLampiran(0, false, 5, self::EKSTENSI);

        return ['ser_dosen' => $aturan, 'f_spmt' => $aturan, 'f_bapel' => $aturan];
    }

    public function urutanTab(): int
    {
        return 10;
    }

    /**
     * Aturan wajib bersyarat legacy `validate_param()` atas NILAI AKHIR baris, plus:
     * - TMT SK tidak kembar untuk jenis ≠ 3 (status selain 2/10, baris sendiri dikecualikan) — legacy :2043-2050.
     * - [V2] jabatan harus berada di sub group (dan satker, bila jabatan terikat satker) yang dipilih; jabatan
     *   koordinasi harus ada (dan aktif bila diubah). Legacy tidak memeriksa (FK DBV-019 akan menolak id yatim).
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $n      = $data + ($lama ?? []);
        $jenis  = (int) ($n['jenis_jabatan'] ?? 0);
        $errors = [];
        $wajib  = static function (string $kolom, string $pesan) use (&$errors, $n): void {
            if (trim((string) ($n[$kolom] ?? '')) === '') {
                $errors[$kolom] = [$pesan];
            }
        };

        if ($jenis === self::JENIS_INSTANSI_LAIN) {
            $wajib('nama_instansi', 'Nama kementerian / lembaga tidak boleh kosong.');
            $wajib('unit', 'Nama unit kerja tidak boleh kosong.');
            $wajib('satker', 'Nama satuan kerja tidak boleh kosong.');
            $wajib('jabatan', 'Nama jabatan tidak boleh kosong.');
        } else {
            if ($jenis === self::JENIS_NOMENKLATUR) {
                $wajib('jenis_mutasi', 'Silakan pilih jenis mutasi.');
            }

            $wajib('id_group_jabatan', 'Silakan pilih group jabatan.');
            $group = (int) ($n['id_group_jabatan'] ?? 0);

            if (in_array($group, [self::GROUP_JFT, self::GROUP_JFU], true)) {
                $wajib('jenis_jabatan_koord', 'Silakan pilih tugas koordinasi.');
            }

            if ($group === self::GROUP_JFT) {
                $wajib('no_induk_jft', 'No. Induk JFT tidak boleh kosong.');
                $wajib('status_jft', 'Silakan pilih status JFT.');
            }

            $wajib('id_sub_group_jabatan', 'Silakan pilih sub group jabatan.');
            $wajib('id_unit', 'Silakan pilih unit kerja.');
            $wajib('id_satker', 'Silakan pilih satuan kerja.');
            $wajib('id_rumpun_jabatan', 'Silakan pilih rumpun jabatan.');

            if ((int) ($n['id_satker'] ?? 0) === self::SATKER_DIPEKERJAKAN) {
                $wajib('jabatan', 'Jabatan tidak boleh kosong.');
            } else {
                $wajib('id_jabatan', 'Silakan pilih jabatan.');
            }

            if (in_array((int) ($n['jenis_jabatan_koord'] ?? 0), [1, 2], true)) {
                $wajib('id_jabatan_koord', 'Silakan pilih jabatan koordinasi.');
            }

            $errors += $this->periksaJabatan($n);
            $errors += $this->periksaKoordinasi($data, $n);
            $errors += $this->periksaAtasanEksplisit($data, $lama);

            if ($this->pegawaiPppk((string) $n[$this->kolomNip()])) {
                $wajib('id_gol_pppk', 'Silakan pilih Golongan PPPK.');
            }
        }

        if ($jenis !== self::JENIS_PLT && ($n['tmtsk'] ?? '') !== '' && ! isset($errors['tmtsk'])) {
            $builder = $this->db()->table($this->tabel())
                ->where($this->kolomNip(), $n[$this->kolomNip()])
                ->where('jenis_jabatan !=', self::JENIS_PLT)
                ->where('tmtsk', $n['tmtsk'])
                ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

            if ($lama !== null) {
                $builder->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
            }

            if ($builder->countAllResults() > 0) {
                $errors['tmtsk'] = ['Riwayat jabatan dengan TMT SK ini sudah terdaftar. Gunakan TMT SK yang berbeda.'];
            }
        }

        return $errors;
    }

    /**
     * Legacy `set_param()` :2085-2330 atas nilai akhir baris. Instansi Lain: kolom nomenklatur dikosongkan [V2] (legacy
     * membiarkan nilai lama karena isian dinonaktifkan di form). Nomenklatur/Plt: nama instansi dari web_config, nama
     * master G-02 disalin; Plt memberi awalan "PLT " pada jabatan & jabatan koordinasi; atribut JFT hanya group JFT;
     * golongan PPPK, PMK, MHPK hanya untuk pegawai PPPK.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $n     = $data + ($lama ?? []);
        $jenis = (int) ($n['jenis_jabatan'] ?? 0);

        // Kolom NOT NULL ber-default legacy: radio "Tidak Ada" (3) dan Mutasi Jabatan (1) bila tidak diisi.
        if (trim((string) ($n['jenis_jabatan_koord'] ?? '')) === '') {
            $data['jenis_jabatan_koord'] = $n['jenis_jabatan_koord'] = 3;
        }

        if (trim((string) ($n['jenis_mutasi'] ?? '')) === '') {
            $data['jenis_mutasi'] = 1;
        }

        if ($jenis === self::JENIS_INSTANSI_LAIN) {
            foreach (self::KOLOM_NOMENKLATUR as $kolom) {
                $data[$kolom] = null;
            }

            if (($n['id_rumpun_jabatan'] ?? null) !== null && (string) $n['id_rumpun_jabatan'] !== '') {
                $data['rumpun_jabatan'] = $this->nama('rumpun_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', $n['id_rumpun_jabatan']);
            } else {
                $data['id_rumpun_jabatan'] = $data['rumpun_jabatan'] = null;
            }

            return $data;
        }

        $plt = $jenis === self::JENIS_PLT;

        $data['nama_instansi']     = $this->namaKementerian();
        $data['group_jabatan']     = $this->nama('group_jabatan', 'id_group_jabatan', 'group_jabatan', $n['id_group_jabatan'] ?? null);
        $data['sub_group_jabatan'] = $this->nama('sub_group_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', $n['id_sub_group_jabatan'] ?? null);
        $data['unit']              = $this->nama('unit', 'id_unit', 'unit', $n['id_unit'] ?? null);
        $data['satker']            = $this->nama('satker', 'id_satker', 'satker', $n['id_satker'] ?? null);
        $data['rumpun_jabatan']    = $this->nama('rumpun_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', $n['id_rumpun_jabatan'] ?? null);

        // [V2] Q3 M4 (keputusan reviewer 13-10-2026): rantai atasan Es I–IV diturunkan server dari struktur_jabatan periode
        // aktif saat tambah atau saat jabatan/satker/unit/sub group berubah; id_atasan_es_N yang diisi klien menang.
        if ($lama === null || $this->posisiBerubah($data, $lama)) {
            foreach ($this->rantaiAtasan($n) as $es => $idAtasan) {
                // Engine mengisi isian yang tidak dikirim dengan null saat tambah: kosong = tidak dipilih klien.
                if (trim((string) ($data["id_atasan_es_{$es}"] ?? '')) === '') {
                    $data["id_atasan_es_{$es}"] = $n["id_atasan_es_{$es}"] = $idAtasan;
                }
            }
        }

        foreach ([1, 2, 3, 4] as $es) {
            $data["atasan_es_{$es}"] = $this->nama('jabatan', 'id_jabatan', 'jabatan', $n["id_atasan_es_{$es}"] ?? null);
        }

        foreach ([3, 4] as $es) {
            $data["atasan_es_{$es}_koord"] = $this->nama('jabatan_koordinasi', 'id_jabatan_koordinasi', 'jabatan', $n["id_atasan_es_{$es}_koord"] ?? null);
        }

        if ((int) ($n['id_satker'] ?? 0) === self::SATKER_DIPEKERJAKAN) {
            $data['id_jabatan']    = null;
            $data['jabatan']       = $n['jabatan'] ?? null;
            $data['kelas_jabatan'] = null;
        } else {
            $jabatan         = $this->baris('jabatan', 'id_jabatan', $n['id_jabatan'] ?? null);
            $data['jabatan'] = $jabatan === null ? null : ($plt ? 'PLT ' : '') . $jabatan['jabatan'];

            if (! $plt) {
                $data['kelas_jabatan'] = ($jabatan['kelas_jabatan'] ?? '') === '' ? null : $jabatan['kelas_jabatan'];
            }
        }

        $koord = $this->baris('jabatan_koordinasi', 'id_jabatan_koordinasi', $n['id_jabatan_koord'] ?? null);

        if (in_array((int) ($n['jenis_jabatan_koord'] ?? 0), [1, 2], true) && $koord !== null) {
            $data['id_jabatan_koord'] = $koord['id_jabatan_koordinasi'];
            $data['jabatan_koord']    = ($plt ? 'PLT ' : '') . $koord['jabatan'];
        } else {
            $data['id_jabatan_koord'] = $data['jabatan_koord'] = null;
        }

        $jft                  = (int) ($n['id_group_jabatan'] ?? 0) === self::GROUP_JFT;
        $data['no_induk_jft'] = $jft && trim((string) ($n['no_induk_jft'] ?? '')) !== '' ? $n['no_induk_jft'] : null;
        $data['status_jft']   = $jft ? ($n['status_jft'] ?? null) : null;

        if ($this->pegawaiPppk((string) $n[$this->kolomNip()])) {
            $data['gol_pppk'] = $this->nama('gol_pppk', 'id_gol_pppk', 'gol_pppk', $n['id_gol_pppk'] ?? null);
        } else {
            foreach (['id_gol_pppk', 'gol_pppk', 'pmk', 'mhpk_mulai', 'mhpk_akhir'] as $kolom) {
                $data[$kolom] = null;
            }
        }

        return $data;
    }

    /**
     * [V2] Jabatan terpilih harus di bawah sub group yang dipilih, dan di satker yang dipilih bila jabatan itu terikat
     * satker (sub group need_satker; JF/pelaksana tanpa satker).
     *
     * @param array<string, mixed> $n
     *
     * @return array<string, list<string>>
     */
    private function periksaJabatan(array $n): array
    {
        if ((int) ($n['id_satker'] ?? 0) === self::SATKER_DIPEKERJAKAN) {
            return [];
        }

        $jabatan = $this->baris('jabatan', 'id_jabatan', $n['id_jabatan'] ?? null);

        if ($jabatan === null) {
            return [];
        }

        if ((string) ($n['id_sub_group_jabatan'] ?? '') !== '' && (string) $jabatan['id_sub_group_jabatan'] !== (string) $n['id_sub_group_jabatan']) {
            return ['id_jabatan' => ["Jabatan {$jabatan['jabatan']} tidak berada di bawah sub group jabatan yang dipilih."]];
        }

        if ($jabatan['id_satker'] !== null && (string) ($n['id_satker'] ?? '') !== '' && (string) $jabatan['id_satker'] !== (string) $n['id_satker']) {
            return ['id_jabatan' => ["Jabatan {$jabatan['jabatan']} tidak berada di satuan kerja yang dipilih."]];
        }

        return [];
    }

    /**
     * Rantai atasan es I–IV jabatan terpilih dari `struktur_jabatan` periode aktif (legacy layanan
     * `hr/services/local/list_all_atasan`, `controllers/hr/services/Local.php:779`):
     *  - jabatan punya simpul struktur → naik lewat `id_jabatan_atasan`; atasan ber-`level_struktur` L (2 Es.I … 5 Es.IV)
     *    menjadi es(L-1);
     *  - tanpa simpul (JF/pelaksana) → per level kandidat struktur di unit (Es.I) / satker (Es.II–IV) yang sama; legacy
     *    memilih otomatis bila kandidatnya tunggal (`populate_es_*` setSelected), jadi hanya kandidat tunggal yang dipakai.
     *    Group struktural (1) hanya sampai eselon di atas sub group-nya (legacy `$maxEs = $idSGJ`).
     * Koordinator/subkoordinator (`*_koord`) tidak diturunkan (legacy memilihnya manual) — tetap isian eksplisit.
     *
     * @param array<string, mixed> $n
     *
     * @return array<int, int|null> es (1–4) => id_jabatan atasan
     */
    private function rantaiAtasan(array $n): array
    {
        $hasil     = [1 => null, 2 => null, 3 => null, 4 => null];
        $idPeriode = $this->periodeAktif();
        $idJabatan = (int) ($n['id_jabatan'] ?? 0);

        if ($idPeriode === null) {
            return $hasil;
        }

        $simpul = $idJabatan > 0 ? $this->simpulStruktur($idPeriode, $idJabatan) : null;

        if ($simpul !== null) {
            $dikunjungi = [$idJabatan => true];

            while ($simpul !== null && $simpul['id_jabatan_atasan'] !== null) {
                $idAtasan = (int) $simpul['id_jabatan_atasan'];

                if (isset($dikunjungi[$idAtasan])) {
                    break;
                }

                $dikunjungi[$idAtasan] = true;
                $simpul                = $this->simpulStruktur($idPeriode, $idAtasan);
                $level                 = (int) ($simpul['level_struktur'] ?? 0);

                if ($level >= 2 && $level <= 5) {
                    $hasil[$level - 1] = $idAtasan;
                }
            }

            return $hasil;
        }

        $maksEs = (int) ($n['id_group_jabatan'] ?? 0) === 1 ? (int) ($n['id_sub_group_jabatan'] ?? 5) : 5;

        foreach ([1, 2, 3, 4] as $es) {
            if ($es >= $maksEs) {
                break;
            }

            $builder = $this->db()->table('struktur_jabatan sj')->select('sj.id_jabatan, jab.jabatan')
                ->join('jabatan jab', 'jab.id_jabatan = sj.id_jabatan', 'inner')
                ->where('sj.id_periode_struktur_jabatan', $idPeriode)->where('sj.status', 1)->where('sj.level_struktur', $es + 1);

            if ($es === 1) {
                $builder->join('satker s', 's.id_satker = jab.id_satker', 'inner')->where('s.id_unit', (int) ($n['id_unit'] ?? 0));
            } else {
                $builder->where('jab.id_satker', (int) ($n['id_satker'] ?? 0));
            }

            $kandidat = array_values(array_filter(
                $builder->get()->getResultArray(),
                // Legacy Es.III struktural tidak memuat jabatan "Koordinator…" (Local.php list_all_atasan).
                static fn (array $r): bool => $es !== 3 || ! str_starts_with(strtolower((string) $r['jabatan']), 'koordinator'),
            ));

            if (count($kandidat) === 1) {
                $hasil[$es] = (int) $kandidat[0]['id_jabatan'];
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $lama
     */
    private function posisiBerubah(array $data, array $lama): bool
    {
        foreach (['jenis_jabatan', 'id_jabatan', 'id_satker', 'id_unit', 'id_group_jabatan', 'id_sub_group_jabatan'] as $kolom) {
            if (array_key_exists($kolom, $data) && (string) $data[$kolom] !== (string) ($lama[$kolom] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * id_atasan_es_N yang dikirim klien (dan berubah) harus simpul struktur periode aktif ber-level N+1.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, list<string>>
     */
    private function periksaAtasanEksplisit(array $data, ?array $lama): array
    {
        $errors    = [];
        $idPeriode = $this->periodeAktif();

        foreach ([1, 2, 3, 4] as $es) {
            $kolom = "id_atasan_es_{$es}";
            $nilai = $data[$kolom] ?? null;

            if ($nilai === null || (string) $nilai === '' || ($lama !== null && (string) $nilai === (string) ($lama[$kolom] ?? ''))) {
                continue;
            }

            $simpul = $idPeriode === null ? null : $this->simpulStruktur($idPeriode, (int) $nilai);

            if ((int) ($simpul['level_struktur'] ?? 0) !== $es + 1) {
                $errors[$kolom] = ["Atasan eselon {$es} tidak terdaftar pada struktur jabatan periode aktif."];
            }
        }

        return $errors;
    }

    private function periodeAktif(): ?int
    {
        $row = $this->db()->table('periode_struktur_jabatan')->select('id_periode_struktur_jabatan')->where('status', 1)
            ->orderBy('id_periode_struktur_jabatan', 'DESC')->limit(1)->get()->getRowArray();

        return $row === null ? null : (int) $row['id_periode_struktur_jabatan'];
    }

    /**
     * @return array{id_jabatan_atasan: int|string|null, level_struktur: int|string}|null
     */
    private function simpulStruktur(int $idPeriode, int $idJabatan): ?array
    {
        /** @var array{id_jabatan_atasan: int|string|null, level_struktur: int|string}|null $row */
        $row = $this->db()->table('struktur_jabatan')->select('id_jabatan_atasan, level_struktur')
            ->where('id_periode_struktur_jabatan', $idPeriode)->where('id_jabatan', $idJabatan)->where('status', 1)
            ->orderBy('id_struktur_jabatan', 'ASC')->limit(1)->get()->getRowArray();

        return $row;
    }

    /**
     * Rujukan `jabatan_koordinasi` harus ada; nilai yang dikirim harus berstatus aktif.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $n
     *
     * @return array<string, list<string>>
     */
    private function periksaKoordinasi(array $data, array $n): array
    {
        $errors = [];

        foreach (self::KOLOM_KOORDINASI as $kolom) {
            if (($n[$kolom] ?? null) === null || (string) $n[$kolom] === '') {
                continue;
            }

            $baris = $this->baris('jabatan_koordinasi', 'id_jabatan_koordinasi', $n[$kolom]);

            if ($baris === null) {
                $errors[$kolom] = ['Jabatan koordinasi tidak ditemukan.'];
            } elseif (array_key_exists($kolom, $data) && (int) $baris['status'] !== 1) {
                $errors[$kolom] = ["Jabatan koordinasi {$baris['jabatan']} sedang non-aktif."];
            }
        }

        return $errors;
    }

    private function pegawaiPppk(string $nip): bool
    {
        $row = $this->db()->table('pegawai')->select('id_jenis_pegawai')->where('nip', $nip)->get()->getRowArray();

        return (int) ($row['id_jenis_pegawai'] ?? 0) === self::JENIS_PEGAWAI_PPPK;
    }

    /**
     * Legacy `web_config.nama_kementerian` (set_param :2186-2188); tabel/baris belum ada → NULL.
     */
    private function namaKementerian(): ?string
    {
        $db = $this->db();
        // information_schema, bukan tableExists(): cache daftar tabel CI4 per koneksi bisa basi (lihat NipCascadeService).
        $ada = $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('web_config')],
        )->getRowArray() !== null;

        if (! $ada) {
            return null;
        }

        $nilai = $db->table('web_config')->select('config_value')->where('config_name', 'nama_kementerian')->get()->getRowArray();
        $nama  = trim((string) ($nilai['config_value'] ?? ''));

        return $nama === '' ? null : $nama;
    }

    private function nama(string $tabel, string $pk, string $kolom, mixed $id): ?string
    {
        $baris = $this->baris($tabel, $pk, $id);

        return $baris === null ? null : (string) $baris[$kolom];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(string $tabel, string $pk, mixed $id): ?array
    {
        if ($id === null || (string) $id === '') {
            return null;
        }

        return $this->db()->table($tabel)->where($pk, $id)->get()->getRowArray();
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }
}
