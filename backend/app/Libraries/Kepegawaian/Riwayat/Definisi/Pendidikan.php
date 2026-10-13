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

/**
 * B-10 Riwayat Pendidikan (WS-1 M2 MAKE-005) — pilot self-service mesin riwayat.
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Pendidikan.php`, `libraries/hr/rwy/L_pendidikan.php`
 * (`vp()` validasi :702-801, `sp()` set_param :803-843, `list()` :27-48, unggah :166-260). Snapshot: dok DBV-012 §5.1.
 * Hak akses: Matriks v2 `hr/rwy/pendidikan/*` = 1, 2, 3, 6, 7; proses = 1, 3 (legacy `process()` userAuth).
 */
final class Pendidikan extends RiwayatDefinisi
{
    /**
     * Jenjang tanpa bidang/jurusan (SD, SMP, SMA sederajat): legacy `vp()` :726 dan `sp()` nem/ipk :833-834.
     *
     * @var list<int>
     */
    public const JENJANG_TANPA_JURUSAN = [1, 2, 3];

    /**
     * Kode "lainnya" legacy (hard-code `vp()` :731, :742; `sp()` :822, :829): isian teks `*_lain` wajib dan hanya disimpan
     * untuk kode ini.
     */
    public const BIDANG_LAIN  = 98;
    public const JURUSAN_LAIN = 1185;

    /**
     * Kode `jenis_rwy` lampiran (konstanta legacy `ARSIP_RWY`, `config/constants.php:202-211`).
     */
    public const LAMPIRAN_IJAZAH            = 14;
    public const LAMPIRAN_PENCANTUMAN_GELAR = 39;
    public const LAMPIRAN_TRANSKRIP_NILAI   = 40;

    /**
     * Ekstensi lampiran legacy (`L_pendidikan.php` :186, :217, :248).
     *
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'pendidikan';
    }

    public function label(): string
    {
        return 'Riwayat Pendidikan';
    }

    public function tabel(): string
    {
        return 'riwayat_pendidikan';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_pendidikan';
    }

    public function fields(): array
    {
        return [
            new MasterField('id_jenjang_pendidikan', 'Jenjang Pendidikan', MasterField::TYPE_REF, required: true, entity: 'jenjang-pendidikan'),
            new MasterField('id_bidang_pendidikan', 'Bidang Pendidikan', MasterField::TYPE_REF, entity: 'bidang-pendidikan'),
            new MasterField('bidang_pendidikan_lain', 'Bidang Pendidikan Lain', maxBytes: 100),
            new MasterField('id_jurusan_pendidikan', 'Jurusan Pendidikan', MasterField::TYPE_REF, entity: 'jurusan-pendidikan', dependsOn: 'id_bidang_pendidikan'),
            new MasterField('jurusan_pendidikan_lain', 'Jurusan Pendidikan Lain', maxBytes: 255),
            new MasterField('institusi_pendidikan', 'Nama Institusi', required: true, maxBytes: 255),
            new MasterField('no_ijazah', 'Nomor Ijazah', required: true, maxBytes: 100),
            new MasterField('tgl_lulus', 'Tanggal Dikeluarkan Ijazah', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk_penc_glr', 'Nomor SK Pencantuman Gelar', maxBytes: 100),
            // DECIMAL(5,2) / DECIMAL(3,2) di DDL; legacy hanya menyimpan nilai > 0 (sp() :833-834).
            new MasterField('nem', 'NEM', MasterField::TYPE_DECIMAL, min: 0, max: 999.99),
            new MasterField('ipk', 'IPK', MasterField::TYPE_DECIMAL, min: 0, max: 9.99),
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
     * Legacy: role 2 (Pegawai PNS) hanya boleh tambah/ubah/hapus selama pegawai sasaran masih CPNS — `$isCPNS` =
     * `pangkat.cpns = 1` dari snapshot `pegawai_kp` (`controllers/hr/rwy/Pendidikan.php:49-54, :70-72, :121-131,
     * :220-231, :454-465`). Role 6/7 dan admin tidak dibatasi; lihat/proses tidak terpengaruh. Keputusan user 12-10-2026.
     */
    public function bolehAksiUntuk(AuthContext $auth, AksiRiwayat $aksi, string $nip): bool
    {
        if ($auth->role() !== Role::PEGAWAI || ! in_array($aksi, [AksiRiwayat::Tambah, AksiRiwayat::Ubah, AksiRiwayat::Hapus], true)) {
            return true;
        }

        $cpns = db_connect()->table('pegawai_kp')
            ->select('pangkat.cpns')
            ->join('pangkat', 'pangkat.id_pangkat = pegawai_kp.id_pangkat', 'left')
            ->where('pegawai_kp.nip', $nip)
            ->get()
            ->getRow();

        return $cpns !== null && (string) $cpns->cpns === '1';
    }

    /**
     * Legacy: role 1 memilih status saat edit pendidikan (`views/hr/employee/rwy/pendidikan/form.php:59-76`,
     * keputusan user 12-10-2026). Diproses hanya bila flag status 3 aktif; Ditolak wajib alasan.
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :30 `ORDER BY status ASC, tgl_lulus ASC` (legacy hanya menampilkan status 0/1; kontrak v2
     * menampilkan semua kecuali 10 agar baris ditolak + alasannya terlihat).
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tgl_lulus' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot(
                tabel: 'pegawai_pendidikan',
                kolom: [
                    'id_riwayat_pendidikan', 'id_jenjang_pendidikan', 'id_bidang_pendidikan', 'id_jurusan_pendidikan',
                    'tgl_lulus', 'no_ijazah', 'no_sk_penc_glr', 'institusi_pendidikan', 'jenjang_pendidikan_singkat',
                    'bidang_pendidikan', 'bidang_pendidikan_lain', 'jurusan_pendidikan', 'jurusan_pendidikan_lain',
                    'nem', 'ipk', 'keterangan', 'glr_awal', 'glr_akhir', 'id_rw_siasn',
                ],
                urutan: ['jenjang_pendidikan.order' => 'DESC', 'tgl_lulus' => 'DESC'],
                join: ['jenjang_pendidikan' => 'jenjang_pendidikan.id_jenjang_pendidikan = riwayat_pendidikan.id_jenjang_pendidikan'],
            ),
        ];
    }

    /**
     * Legacy: Ijazah WAJIB saat tambah, dan saat ubah bila baris belum punya Ijazah (`vp()` :756-762, :777-784);
     * transkrip & SK pencantuman gelar opsional; semua 5 MB (:178, :209, :240), jpg|jpeg|png|gif|pdf (:186, :217, :248).
     */
    public function lampiran(): array
    {
        return [
            new AturanLampiran(self::LAMPIRAN_IJAZAH, true, 5, self::EKSTENSI),
            new AturanLampiran(self::LAMPIRAN_PENCANTUMAN_GELAR, false, 5, self::EKSTENSI),
            new AturanLampiran(self::LAMPIRAN_TRANSKRIP_NILAI, false, 5, self::EKSTENSI),
        ];
    }

    public function urutanTab(): int
    {
        return 40;
    }

    /**
     * Aturan bersyarat legacy `vp()`: jenjang di luar 1-3 → bidang & jurusan wajib; bidang 98 / jurusan 1185 → isian
     * "lain" wajib; tanggal ijazah tidak boleh kembar dengan riwayat pegawai itu yang berstatus selain 2/10.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $errors = [];

        if (! in_array((int) ($nilai['id_jenjang_pendidikan'] ?? 0), self::JENJANG_TANPA_JURUSAN, true)) {
            if (self::kosong($nilai['id_bidang_pendidikan'] ?? null)) {
                $errors['id_bidang_pendidikan'] = ['Silakan pilih bidang pendidikan.'];
            } elseif ((int) $nilai['id_bidang_pendidikan'] === self::BIDANG_LAIN && self::kosong($nilai['bidang_pendidikan_lain'] ?? null)) {
                $errors['bidang_pendidikan_lain'] = ['Bidang pendidikan lain tidak boleh kosong.'];
            }

            if (self::kosong($nilai['id_jurusan_pendidikan'] ?? null)) {
                $errors['id_jurusan_pendidikan'] = ['Silakan pilih jurusan pendidikan.'];
            } elseif ((int) $nilai['id_jurusan_pendidikan'] === self::JURUSAN_LAIN && self::kosong($nilai['jurusan_pendidikan_lain'] ?? null)) {
                $errors['jurusan_pendidikan_lain'] = ['Jurusan pendidikan lain tidak boleh kosong.'];
            }
        }

        // Atas NILAI AKHIR setiap tambah/ubah (legacy vp() memeriksa di setiap add/edit), juga PUT parsial tanpa tgl_lulus.
        if (! self::kosong($nilai['tgl_lulus'] ?? null) && $this->tanggalKembar((string) $nilai[$this->kolomNip()], (string) $nilai['tgl_lulus'], $lama)) {
            $errors['tgl_lulus'] = ['Riwayat pendidikan dengan Tanggal Dikeluarkan Ijazah ini sudah terdaftar. Gunakan tanggal yang berbeda.'];
        }

        return $errors;
    }

    /**
     * Salin nama master + normalisasi legacy `sp()` :803-843 dari nilai akhir baris.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai   = $data + ($lama ?? []);
        $db      = db_connect();
        $jenjang = isset($nilai['id_jenjang_pendidikan']) ? (int) $nilai['id_jenjang_pendidikan'] : null;
        $bidang  = self::kosong($nilai['id_bidang_pendidikan'] ?? null) ? null : (int) $nilai['id_bidang_pendidikan'];
        $jurusan = self::kosong($nilai['id_jurusan_pendidikan'] ?? null) ? null : (int) $nilai['id_jurusan_pendidikan'];

        $data['jenjang_pendidikan_singkat'] = $jenjang === null ? null
            : ($db->table('jenjang_pendidikan')->select('jenjang_pendidikan_singkat')->where('id_jenjang_pendidikan', $jenjang)->get()->getRow()->jenjang_pendidikan_singkat ?? null);
        $data['bidang_pendidikan'] = $bidang === null ? null
            : ($db->table('bidang_pendidikan')->select('bidang_pendidikan')->where('id_bidang_pendidikan', $bidang)->get()->getRow()->bidang_pendidikan ?? null);
        $data['jurusan_pendidikan'] = $jurusan === null ? null
            : ($db->table('jurusan_pendidikan')->select('jurusan_pendidikan')->where('id_jurusan_pendidikan', $jurusan)->get()->getRow()->jurusan_pendidikan ?? null);

        $data['bidang_pendidikan_lain']  = $bidang === self::BIDANG_LAIN ? ($nilai['bidang_pendidikan_lain'] ?? null) : null;
        $data['jurusan_pendidikan_lain'] = $jurusan === self::JURUSAN_LAIN ? ($nilai['jurusan_pendidikan_lain'] ?? null) : null;

        $tanpaJurusan = in_array($jenjang, self::JENJANG_TANPA_JURUSAN, true);
        $data['nem']  = $tanpaJurusan && (float) ($nilai['nem'] ?? 0) > 0 ? $nilai['nem'] : null;
        $data['ipk']  = ! $tanpaJurusan && (float) ($nilai['ipk'] ?? 0) > 0 ? $nilai['ipk'] : null;

        return $data;
    }

    /**
     * @param array<string, mixed>|null $lama
     */
    private function tanggalKembar(string $nip, string $tglLulus, ?array $lama): bool
    {
        $builder = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nip)
            ->where('tgl_lulus', $tglLulus)
            // Sama dengan legacy `status NOT IN ('2', '10')`: baris ber-status NULL tidak ikut (logika SQL).
            ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

        if ($lama !== null) {
            $builder->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        return $builder->countAllResults() > 0;
    }

    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || $nilai === '';
    }
}
