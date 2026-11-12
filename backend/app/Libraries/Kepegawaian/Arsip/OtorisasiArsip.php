<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;

/**
 * Otorisasi halaman Arsip F-10 Tahap 1 (PRD F-10 §5, §12.1; F10-07, F10-30).
 *
 * Lihat (folder, daftar, `buka`) = role 1–7 + `PegawaiScope::bolehLihat` atas {nip} (jalur detail). TANPA izin Definisi
 * per kode (F10-30): legacy menampilkan semua baris per NIP. Urutan galat (§8.1): 403 role → 403 lingkup (NIP tak ada
 * bagi role ber-lingkup = 403, sama dengan `GET pegawai/{nip}`) → 404 NIP. Role juga disaring di route
 * (`Role::filter(1–7)`), karena `PegawaiScope::ROLE_LIHAT_SEMUA` memuat role 8; cek di sini = lapis kedua.
 *
 * Aturan tulis Tahap 2 (lingkup ubah, kunci baris Disetujui, `ArsipDataUmum::bolehTulis()` kode 37; F10-31/F10-38)
 * menyusul di MAKE-092.
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

    public function __construct(
        private readonly PegawaiScopeInterface $scope,
        private readonly BaseConnection $db,
    ) {
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
