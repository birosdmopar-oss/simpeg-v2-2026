<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;

/**
 * B-15 Riwayat Angka Kredit / PAK (WS-1 M5 MAKE-008), snapshot `pegawai_ak`.
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Ak.php` (add :259-370 — syarat kelompok jabatan :290,
 * satu pengajuan menunggu per jabatan :305; status 1 untuk admin :322/:419), `libraries/hr/rwy/L_ak.php` (list() :26
 * status 0/1 `tgl_pak DESC`, form() :312-478 isian turunan, vp() :761-873, sp() :875-929, process_ak() :1231).
 *
 * Hak akses (keputusan user 13-10-2026): lihat 1, 2, 3, 4, 7 — role 2 dan 7 (UL_PEGAWAI) hanya bila pegawai sasaran
 * pejabat JF tertentu (`pegawai_mutasi_jabatan.id_group_jabatan` = 2 = sesi legacy `is_jft`,
 * `core/MY_Controller.php:89-98`); role 6 tidak. Tambah/ubah/hapus/proses hanya role 1. Legacy punya dua jalur proses:
 * `process` (riwayat AK menunggu) dan `process_cak` (role 1 dan 3, form_process_ak, untuk baris Current AK `jenis_ak` 1
 * yang menunggu). Keduanya = aksi proses engine (setujui/tolak baris status 0) untuk role 1 saja (keputusan A1) — role 3
 * kehilangan `process_cak` legacy. Proses setujui dan tolak mengisi `appr_at`/`appr_by` lewat hook afterProses (legacy
 * process_ak() :1240-1244).
 *
 * [V2] Isian turunan diisi SERVER saat tambah (input klien untuk kolom ini diabaikan; legacy mengirimnya dari form
 * read-only): nama, jabatan/no induk JF/TMT jabatan dari `pegawai_mutasi_jabatan`, pangkat/TMT pangkat dari
 * `pegawai_kp`, AK kumulatif dari `dm_ak_jf`. Masa kerja sampai tanggal PAK (legacy: sampai hari ini) dihitung saat
 * tambah dan saat tanggal PAK berubah; jumlah AK = AK lama + AK baru (legacy: dihitung form) saat tambah dan saat AK
 * lama/baru dikirim — kecuali baris Current AK (`jenis_ak` 1) yang tidak pernah dihitung ulang. `jenis_ak` tidak
 * di-reset saat ubah (legacy sp() menulis 2 setiap simpan). Tabel `dm_ak_jf` belum ada di skema v2 (kebutuhan DBV):
 * selama belum ada, AK kumulatif = 0 (nilai bawaan legacy sp() :888-889).
 */
final class Ak extends RiwayatDefinisi
{
    public const KOLOM_BERKAS = 'file_pak';

    /**
     * Kelompok jabatan yang boleh punya PAK (legacy `Ak.php:290`): 2 = JF tertentu, 3 = JF umum.
     *
     * @var list<int>
     */
    public const GROUP_BOLEH_PAK = [2, 3];

    public const GROUP_JFT = 2;

    /**
     * `jenis_ak` 1 = Current/Starting AK (legacy), 2 = pengajuan riwayat AK.
     */
    public const JENIS_CURRENT = 1;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

    /**
     * Kolom yang disalin ke snapshot `pegawai_ak` (kolom bisnis bersama `riwayat_ak` dan `pegawai_ak`).
     *
     * @var list<string>
     */
    private const KOLOM_SNAPSHOT = [
        'id_riwayat_ak', 'id_jabatan', 'id_pangkat', 'jenis_ak', 'nama', 'no_induk_jf', 'no_hpak', 'tgl_pak', 'periode_awal',
        'periode_akhir', 'ak_kum_kp', 'ak_kum_next_jen', 'ak_terakhir', 'pengajuan_ak', 'nilai_ak', 'nama_pym', 'jab_pym',
        'flag_instansi_ym', 'instansi_ym', 'jabatan', 'tmt_jab', 'mker_th_jab', 'mker_bl_jab', 'pangkat', 'gol_ruang',
        'tmt_pang', 'mker_th_pang', 'mker_bl_pang', 'keterangan', 'file_pak',
    ];

    public function jenis(): string
    {
        return 'ak';
    }

    public function label(): string
    {
        return 'Riwayat Angka Kredit';
    }

    public function tabel(): string
    {
        return 'riwayat_ak';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_ak';
    }

    public function fields(): array
    {
        $ak = static fn (string $nama, string $label): MasterField => new MasterField($nama, $label, MasterField::TYPE_DECIMAL, required: true, min: 0, max: 99999.999);

        return [
            new MasterField('no_hpak', 'No. PAK', required: true, maxBytes: 50),
            new MasterField('tgl_pak', 'Tanggal PAK', MasterField::TYPE_DATE, required: true),
            new MasterField('periode_awal', 'Periode Awal Penilaian', MasterField::TYPE_DATE, required: true),
            new MasterField('periode_akhir', 'Periode Akhir Penilaian', MasterField::TYPE_DATE, required: true),
            new MasterField('flag_instansi_ym', 'Instansi yang Menetapkan', MasterField::TYPE_SELECT, required: true, options: [0 => 'Kementerian', 1 => 'Instansi Lain']),
            new MasterField('instansi_ym', 'Nama Instansi', required: true, maxBytes: 256),
            new MasterField('nama_pym', 'Nama Pejabat yang Menetapkan', required: true, maxBytes: 256),
            new MasterField('jab_pym', 'Jabatan yang Menetapkan', required: true, maxBytes: 256),
            $ak('ak_terakhir', 'AK Lama'),
            $ak('pengajuan_ak', 'AK Baru'),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $admin = [Role::SUPER_ADMIN];

        return [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::PPPK],
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
     * CHECK `status IN (0, 1, 2, 10)` — tanpa 3 Diproses.
     */
    public function pemetaanStatus(): array
    {
        return Karpeg::statusTanpaDiproses();
    }

    /**
     * Legacy: ubah oleh admin selalu menyimpan status 1 (`controllers/hr/rwy/Ak.php:419`).
     */
    public function statusSetelahUbah(int $role, ?StatusRiwayat $lama): ?StatusRiwayat
    {
        return $role === Role::SUPER_ADMIN ? StatusRiwayat::Disetujui : parent::statusSetelahUbah($role, $lama);
    }

    /**
     * UL_PEGAWAI role 2 dan 7 hanya melihat bila pegawai sasaran pejabat JF tertentu (legacy `Ak.php:17-23`, `is_jft`;
     * role 7 = keputusan user 13-10-2026); tambah hanya untuk pegawai berkelompok jabatan JF (legacy `Ak.php:290`).
     */
    public function bolehAksiUntuk(AuthContext $auth, AksiRiwayat $aksi, string $nip): bool
    {
        if ($aksi === AksiRiwayat::Lihat) {
            return ! self::lihatHanyaJft($auth) || self::groupJabatan($nip) === self::GROUP_JFT;
        }

        if ($aksi === AksiRiwayat::Tambah) {
            return in_array(self::groupJabatan($nip), self::GROUP_BOLEH_PAK, true);
        }

        return true;
    }

    /**
     * Legacy list() :29 hanya status 0 dan 1.
     */
    public function saringDaftar(BaseBuilder $builder): void
    {
        $builder->whereIn($this->kolomStatus(), [StatusRiwayat::Menunggu->value, StatusRiwayat::Disetujui->value]);
    }

    public function urutanDaftar(): array
    {
        return ['tgl_pak' => 'DESC', $this->primaryKey() => 'DESC'];
    }

    public function snapshot(): array
    {
        return [new AturanSnapshot('pegawai_ak', self::KOLOM_SNAPSHOT, urutan: ['tgl_pak' => 'DESC', 'periode_akhir' => 'DESC', 'id_riwayat_ak' => 'DESC'])];
    }

    /**
     * File PAK: wajib saat tambah dan saat ubah bila belum ada; 1 MB (legacy `L_ak.php` vp() :812-842, insert :527-531).
     */
    public function kolomBerkas(): array
    {
        return [self::KOLOM_BERKAS => new AturanLampiran(0, true, 1, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 85;
    }

    /**
     * Legacy vp(): periode tidak boleh beririsan dengan PAK lain berstatus 0/1; tambah ditolak bila masih ada PAK
     * menunggu untuk jabatan pegawai saat ini (legacy `Ak.php:305`).
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $nip    = (string) $nilai[$this->kolomNip()];
        $errors = [];
        $aktif  = [StatusRiwayat::Menunggu->value, StatusRiwayat::Disetujui->value];

        $irisan = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nip)
            ->where('periode_awal <=', $nilai['periode_akhir'] ?? null)
            ->where('periode_akhir >=', $nilai['periode_awal'] ?? null)
            ->whereIn($this->kolomStatus(), $aktif);

        if ($lama !== null) {
            $irisan->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        if ($irisan->countAllResults() > 0) {
            $errors['periode_awal'] = ['Periode penilaian beririsan dengan PAK lain yang sudah terdaftar.'];
        }

        if ($lama === null) {
            $menunggu = db_connect()->table($this->tabel() . ' rak')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = rak.nip AND pmj.id_jabatan = rak.id_jabatan')
                ->where('rak.nip', $nip)
                ->where('rak.' . $this->kolomStatus(), StatusRiwayat::Menunggu->value)
                ->countAllResults();

            if ($menunggu > 0) {
                $errors['id_jabatan'] = ['Masih ada PAK menunggu persetujuan untuk jabatan pegawai saat ini.'];
            }
        }

        return $errors;
    }

    /**
     * Tambah: isian turunan dari data pegawai saat ini ([V2], lihat docblock kelas). Jumlah AK dihitung saat tambah atau
     * bila AK lama/baru dikirim (tidak untuk Current AK `jenis_ak` 1); masa kerja saat tambah atau bila tanggal PAK
     * berubah. `jenis_ak` tidak di-reset saat ubah ([V2]).
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        if ($lama === null) {
            $data = self::turunan((string) $data[$this->kolomNip()]) + $data;

            $data['jenis_ak']   = 2;
            $data['created_by'] = $auth->idPengguna();
        }

        $nilai     = $data + ($lama ?? []);
        $akDikirim = array_key_exists('ak_terakhir', $data) || array_key_exists('pengajuan_ak', $data);

        if ($lama === null || ($akDikirim && (int) ($lama['jenis_ak'] ?? 0) !== self::JENIS_CURRENT)) {
            $data['nilai_ak'] = (float) ($nilai['ak_terakhir'] ?? 0) + (float) ($nilai['pengajuan_ak'] ?? 0);
        }

        if ($lama === null || (array_key_exists('tgl_pak', $data) && (string) $data['tgl_pak'] !== (string) ($lama['tgl_pak'] ?? ''))) {
            foreach (['jab' => 'tmt_jab', 'pang' => 'tmt_pang'] as $akhiran => $kolomTmt) {
                [$data["mker_th_{$akhiran}"], $data["mker_bl_{$akhiran}"]] = self::masaKerja($nilai[$kolomTmt] ?? null, (string) ($nilai['tgl_pak'] ?? ''));
            }
        }

        return $data;
    }

    /**
     * Legacy process_ak() :1240-1244 mengisi `appr_at`/`appr_by` (tabel tanpa `approved_by`) untuk setujui DAN tolak.
     */
    public function afterProses(string $aksi, array $baris, AuthContext $auth): void
    {
        db_connect()->table($this->tabel())
            ->where($this->primaryKey(), $baris[$this->primaryKey()])
            ->update(['appr_at' => Time::now()->toDateTimeString(), 'appr_by' => $auth->idPengguna()]);
    }

    /**
     * Pemanggil UL_PEGAWAI yang berhak lihat (role 2, 7) hanya melihat AK/AK SIASN pejabat JF tertentu.
     */
    public static function lihatHanyaJft(AuthContext $auth): bool
    {
        return in_array($auth->role(), [Role::PEGAWAI, Role::PPPK], true);
    }

    /**
     * Kelompok jabatan (`pegawai_mutasi_jabatan.id_group_jabatan`) pegawai $nip saat ini; null = tidak ada.
     */
    public static function groupJabatan(string $nip): ?int
    {
        $row = db_connect()->table('pegawai_mutasi_jabatan')->select('id_group_jabatan')->where('nip', $nip)->get()->getRowArray();

        return isset($row['id_group_jabatan']) ? (int) $row['id_group_jabatan'] : null;
    }

    /**
     * Isian turunan legacy form() :312-405 untuk pegawai $nip.
     *
     * @return array<string, mixed>
     */
    public static function turunan(string $nip): array
    {
        $db  = db_connect();
        $peg = $db->table('pegawai peg')
            ->select('peg.glr_awal, peg.nama, peg.glr_akhir, pmj.id_sub_group_jabatan, pmj.id_jabatan, pmj.jabatan, '
                . 'pmj.tmtsk AS tmt_jab, pmj.no_induk_jft, jjf.id_jenjang_jf, jjf.kategori_jf, jjf.`order` AS lvl_jenjang, '
                . 'pkp.id_pangkat, pkp.pangkat, pkp.gol_ruang, pkp.tmtsk AS tmt_pang, pang.`order` AS lvl_pangkat', false)
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pangkat pang', 'pang.id_pangkat = pkp.id_pangkat', 'left')
            ->where('peg.nip', $nip)
            ->get()
            ->getRowArray() ?? [];

        $nama = trim(trim((string) ($peg['glr_awal'] ?? '')) . ' ' . trim((string) ($peg['nama'] ?? '')));

        if (trim((string) ($peg['glr_akhir'] ?? '')) !== '') {
            $nama .= ', ' . trim((string) $peg['glr_akhir']);
        }

        $idJabatan = isset($peg['id_jabatan']) ? (int) $peg['id_jabatan'] : null;
        $idPangkat = isset($peg['id_pangkat']) ? (int) $peg['id_pangkat'] : null;
        $tmtJab    = $peg['tmt_jab'] ?? null;
        $tmtPang   = $peg['tmt_pang'] ?? null;

        // TMT jabatan: mundur sepanjang riwayat jabatan disetujui sebelumnya masih jabatan yang sama (legacy :331-345).
        if ($idJabatan !== null && $tmtJab !== null) {
            $rows = $db->table('riwayat_mutasi_jabatan')->select('id_jabatan, tmtsk')
                ->where('nip', $nip)->where('tmtsk <', $tmtJab)->where('status', StatusRiwayat::Disetujui->value)
                ->whereIn('jenis_jabatan', [1, 2])->orderBy('tmtsk', 'DESC')->get()->getResultArray();

            foreach ($rows as $row) {
                if ((int) $row['id_jabatan'] !== $idJabatan) {
                    break;
                }

                $tmtJab = $row['tmtsk'];
            }
        }

        // TMT pangkat: KP disetujui pertama dengan pangkat yang sama (legacy :348-355).
        if ($idPangkat !== null && $tmtPang !== null) {
            $row = $db->table('riwayat_kp')->select('tmtsk')
                ->where('nip', $nip)->where('id_pangkat', $idPangkat)->where('tmtsk <=', $tmtPang)
                ->where('status', StatusRiwayat::Disetujui->value)->orderBy('tmtsk', 'ASC')->limit(1)->get()->getRowArray();
            $tmtPang = $row['tmtsk'] ?? $tmtPang;
        }

        return [
            'nama'        => mb_substr($nama, 0, 150),
            'id_jabatan'  => $idJabatan,
            'jabatan'     => $peg['jabatan'] ?? null,
            'no_induk_jf' => $peg['no_induk_jft'] ?? null,
            'tmt_jab'     => $tmtJab,
            'id_pangkat'  => $idPangkat,
            'pangkat'     => $peg['pangkat'] ?? null,
            'gol_ruang'   => $peg['gol_ruang'] ?? null,
            'tmt_pang'    => $tmtPang,
        ] + self::akKumulatif($nip, $peg, $idJabatan, $idPangkat);
    }

    /**
     * AK kumulatif untuk KP dan jenjang berikutnya dari master `dm_ak_jf` (legacy form() :357-405), dikurangi AK
     * terakhir di snapshot `pegawai_ak` untuk jabatan + pangkat yang sama.
     *
     * @param array<string, mixed> $peg
     *
     * @return array{ak_kum_kp: float, ak_kum_next_jen: float}
     */
    private static function akKumulatif(string $nip, array $peg, ?int $idJabatan, ?int $idPangkat): array
    {
        $db     = db_connect();
        $kumKp  = 0.0;
        $kumJen = 0.0;

        if (($peg['id_jenjang_jf'] ?? null) !== null && self::adaMasterAkJf()) {
            $berikut = $db->table('jabatan jab')->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf')
                ->where('jab.id_sub_group_jabatan', $peg['id_sub_group_jabatan'])->where('jjf.kategori_jf', $peg['kategori_jf'])
                ->where('jjf.order >', $peg['lvl_jenjang'])->countAllResults() > 0;

            $rows = $db->table('dm_ak_jf akjf')->select('akjf.ak, pang.`order` AS lvl', false)
                ->join('jabatan jab', 'jab.id_jabatan = akjf.id_jabatan', 'left')
                ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
                ->join('pangkat pang', 'pang.id_pangkat = akjf.id_pangkat', 'left')
                ->where('jab.id_sub_group_jabatan', $peg['id_sub_group_jabatan'])->where('jjf.kategori_jf', $peg['kategori_jf'])
                ->where('jjf.id_jenjang_jf', $peg['id_jenjang_jf'])->get()->getResultArray();

            foreach ($rows as $row) {
                if ((int) $row['lvl'] <= (int) ($peg['lvl_pangkat'] ?? 0)) {
                    $kumKp += (float) $row['ak'];
                }

                if ($berikut) {
                    $kumJen += (float) $row['ak'];
                }
            }
        }

        $sebelumnya = (float) ($db->table('pegawai_ak')->select('nilai_ak')->where('nip', $nip)
            ->where('id_jabatan', $idJabatan)->where('id_pangkat', $idPangkat)->get()->getRowArray()['nilai_ak'] ?? 0);

        return [
            'ak_kum_kp'       => $kumKp > 0 ? $kumKp - $sebelumnya : $kumKp,
            'ak_kum_next_jen' => $kumJen > 0 ? $kumJen - $sebelumnya : $kumJen,
        ];
    }

    /**
     * Master `dm_ak_jf` sudah ada di skema (belum ada di migration v2 — kebutuhan DBV). Dibaca langsung dari
     * information_schema, tanpa cache daftar tabel.
     */
    private static function adaMasterAkJf(): bool
    {
        $db = db_connect();

        return $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('dm_ak_jf')],
        )->getRow() !== null;
    }

    /**
     * Masa kerja (tahun, bulan) dari $tmt sampai $sampai (legacy :450-462); [null, null] bila salah satu kosong; [V2] [0, 0]
     * bila $tmt sesudah $sampai.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function masaKerja(mixed $tmt, string $sampai): array
    {
        if ($tmt === null || trim((string) $tmt) === '' || trim($sampai) === '') {
            return [null, null];
        }

        $diff = (new DateTimeImmutable((string) $tmt))->diff(new DateTimeImmutable($sampai));

        return $diff->invert === 1 ? [0, 0] : [$diff->y, $diff->m];
    }
}
