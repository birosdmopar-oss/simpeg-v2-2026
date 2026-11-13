<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ArsipDataUmum;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Database\BaseConnection;

/**
 * Otorisasi halaman Arsip F-10 Tahap 1 (PRD F-10 §5, §12.1; F10-07, F10-30).
 *
 * Lihat (folder, daftar, `buka`) = role 1–7 + `PegawaiScope::bolehLihat` atas {nip} (jalur detail). TANPA izin Definisi
 * per kode (F10-30): legacy menampilkan semua baris per NIP. Urutan galat (§8.1): 403 role → 403 lingkup (NIP tak ada
 * bagi role ber-lingkup = 403, sama dengan `GET pegawai/{nip}`) → 404 NIP. Role juga disaring di route
 * (`Role::filter(1–7)`), karena `PegawaiScope::ROLE_LIHAT_SEMUA` memuat role 8; cek di sini = lapis kedua.
 *
 * Tulis Tahap 2 (MAKE-092; §6.1, §12.1, F10-31, F10-38): role 1, 2, 3, 6, 7 + `PegawaiScope::bolehUbah` atas {nip}
 * (2/6/7 NIP sendiri, 3 lingkup detail ubah, 4/5/8 tidak pernah); urutan galat sama (403 role → 403 lingkup → 404 NIP).
 * Izin Definisi per kode tetap TIDAK dipakai (F10-30); yang dipakai dari B-18 hanya (a) kunci baris Disetujui untuk
 * `Role::UL_PEGAWAI` pada tujuan Hubungkan (`RiwayatDefinisi::kunciBarisDisetujui()`, pesan sama dengan
 * `OtorisasiLampiran`) dan (b) `ArsipDataUmum::bolehTulis()` untuk Ubah Nama kode 37 (role 1). Kode 38 ikut legacy
 * (QF10-8): tanpa `bolehTulis()`.
 */
final class OtorisasiArsip
{
    /**
     * Role yang boleh membuka Arsip (F10-07). Role 8 tidak.
     *
     * @var list<int>
     */
    public const ROLE_LIHAT = [
        Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK,
    ];

    /**
     * Role yang pembukaan arsip pegawai LAIN-nya dicatat (F10-17).
     *
     * @var list<int>
     */
    public const ROLE_DICATAT = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI];

    /**
     * Role aksi tulis Tahap 2 (Edit/Hubungkan/Hapus, `opsi-hubungkan`); 4/5/8 tidak (AC2-02).
     *
     * @var list<int>
     */
    public const ROLE_TULIS = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    /**
     * Kode tujuan Hubungkan (F10-28) [K], urutan PRD. 37/39–42 dan 43–50 tidak ditawarkan.
     *
     * @var list<int>
     */
    public const KODE_HUBUNGKAN = [1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, 38];

    public const PESAN_KUNCI = 'Data yang sudah disetujui tidak dapat diubah.';

    public const PESAN_DATA_UMUM = 'Anda tidak berhak mengubah arsip data umum pegawai ini.';

    public function __construct(
        private readonly PegawaiScopeInterface $scope,
        private readonly BaseConnection $db,
        private readonly ?RiwayatRegistryInterface $registry = null,
    ) {
    }

    /**
     * Boleh menulis arsip {nip} (Tahap 2); mengembalikan identitas ringkas pegawai. 403 role → 403 lingkup ubah → 404.
     *
     * @return array{nip: string, nama: string|null, glr_depan: string|null, glr_belakang: string|null}
     */
    public function bolehTulis(AuthContext $auth, string $nip): array
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), self::ROLE_TULIS, true)) {
            throw new ForbiddenException('Anda tidak berhak mengubah arsip pegawai.');
        }

        if (! $this->scope->bolehUbah($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengubah data pegawai ini.');
        }

        return $this->pegawai($nip);
    }

    /**
     * Aksi per aktor untuk {nip} (sesudah bolehLihat): `tulis` = role tulis + lingkup ubah; `jenis_hubungkan` = kode
     * F10-28 yang boleh dituju aktor (UL_PEGAWAI: tanpa kode milik Definisi yang mengunci baris Disetujui, karena tujuan
     * Hubungkan selalu Disetujui [K]; saat ini hanya 38, F10-38 (3)).
     *
     * @return array{tulis: bool, jenis_hubungkan: list<int>}
     */
    public function peran(AuthContext $auth, string $nip): array
    {
        $tulis = in_array($auth->role(), self::ROLE_TULIS, true) && $this->scope->bolehUbah($auth, $nip);

        return [
            'tulis'           => $tulis,
            'jenis_hubungkan' => $tulis ? array_values(array_filter(self::KODE_HUBUNGKAN, fn (int $kode): bool => ! $this->kodeTerkunci($auth, $kode))) : [],
        ];
    }

    /**
     * `aksi` satu baris (F10-38 (4), §9.3): Edit semua baris kecuali kode 37 bagi selain role 1; Hubungkan/Hapus hanya
     * kode 0 (Hubungkan juga butuh `jenis_hubungkan` tidak kosong).
     *
     * @param array{tulis: bool, jenis_hubungkan: list<int>} $peran
     *
     * @return array{edit: bool, hubungkan: bool, hapus: bool}
     */
    public static function aksiBaris(AuthContext $auth, array $peran, ?int $kode): array
    {
        $tulis = $peran['tulis'];

        return [
            'edit'      => $tulis && ($kode !== ArsipDataUmum::KODE_STATUS || ArsipDataUmum::bolehTulis($auth, ArsipDataUmum::KODE_STATUS, '')),
            'hubungkan' => $tulis && $kode === 0 && $peran['jenis_hubungkan'] !== [],
            'hapus'     => $tulis && $kode === 0,
        ];
    }

    /**
     * Tujuan kode $kode terkunci bagi $auth (UL_PEGAWAI + Definisi pemilik kode `kunciBarisDisetujui()`); kode tanpa
     * Definisi (38) tidak terkunci.
     */
    public function kodeTerkunci(AuthContext $auth, int $kode): bool
    {
        if (! in_array($auth->role(), Role::UL_PEGAWAI, true)) {
            return false;
        }

        $definisi = $this->definisi($kode);

        return $definisi !== null && $definisi->kunciBarisDisetujui();
    }

    /**
     * Definisi pemilik kode tujuan (RiwayatRegistry); null untuk kode tanpa Definisi (38).
     */
    public function definisi(int $kode): ?RiwayatDefinisi
    {
        return ($this->registry ?? service('riwayatRegistry'))->definisiUntukLampiran($kode);
    }

    /**
     * Boleh melihat arsip {nip}; mengembalikan identitas ringkas pegawai.
     *
     * @return array{nip: string, nama: string|null, glr_depan: string|null, glr_belakang: string|null}
     */
    public function bolehLihat(AuthContext $auth, string $nip): array
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), self::ROLE_LIHAT, true)) {
            throw new ForbiddenException('Anda tidak berhak mengakses arsip pegawai.');
        }

        if (! $this->scope->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        return $this->pegawai($nip);
    }

    /**
     * @return array{nip: string, nama: string|null, glr_depan: string|null, glr_belakang: string|null}
     */
    private function pegawai(string $nip): array
    {
        $row = $this->db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        return [
            'nip'          => (string) $row['nip'],
            'nama'         => $row['nama'] === null ? null : (string) $row['nama'],
            'glr_depan'    => $row['glr_awal'] === null ? null : (string) $row['glr_awal'],
            'glr_belakang' => $row['glr_akhir'] === null ? null : (string) $row['glr_akhir'],
        ];
    }

    /**
     * Pembukaan arsip {nip} oleh $auth wajib dicatat (F10-17): role 1/3/4/5 dan bukan NIP sendiri.
     */
    public static function wajibDicatat(AuthContext $auth, string $nip): bool
    {
        return in_array($auth->role(), self::ROLE_DICATAT, true) && $auth->nip() !== $nip;
    }
}
