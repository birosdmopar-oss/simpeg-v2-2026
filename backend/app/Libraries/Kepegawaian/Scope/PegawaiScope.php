<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Scope;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Lingkup akses data pegawai — implementasi nyata WS-2 (MAKE-009) untuk PegawaiScopeInterface.
 *
 * Aturan per role (Matriks v2 `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, keputusan #4/#10):
 *
 * | Role                       | Lihat                                   | Ubah                    |
 * |----------------------------|-----------------------------------------|-------------------------|
 * | 1 Super Admin              | semua pegawai                           | semua pegawai           |
 * | 4 Admin View, 5 Menteri, 8 | semua pegawai (view lintas satker)      | tidak ada               |
 * | 2/6/7 (UL_PEGAWAI)         | NIP sendiri (claim `nip`)               | NIP sendiri             |
 * | 3 Admin Satker             | pegawai di satker/unit admin + diri     | sama dengan lihat       |
 *
 * Role 3 = port pemeriksaan akses legacy `hr/employee/edit|detail` [K] (`controllers/hr/Employee.php`): admin yang punya
 * `id_satker` hanya mengakses pegawai yang snapshot jabatannya (`pegawai_mutasi_jabatan`) di satker itu; admin tanpa
 * satker → pegawai di unit admin. Admin tanpa satker dan unit → tidak ada (fail-closed).
 *
 * Deviasi [V2] dari legacy (dicatat di progres 03-Kepegawaian): legacy MELOLOSKAN pegawai yang snapshot jabatannya
 * kosong (`$dataset['id_satker'] != ''`) ke semua admin satker; v2 menolaknya (pegawai tanpa pmj / satker NULL hanya
 * terlihat oleh role 1/4/5/8).
 *
 * Claim `id_satker`/`id_unit` akun bertipe VARCHAR (`pengguna`, DBV-009 belum diputus) sedangkan kolom pmj INT: nilai
 * claim di-cast ke int hanya bila berupa angka kanonik; selain itu dianggap kosong (fail-closed).
 */
final class PegawaiScope implements PegawaiScopeInterface
{
    /**
     * Role yang melihat seluruh pegawai.
     *
     * @var list<int>
     */
    public const ROLE_LIHAT_SEMUA = [Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    /**
     * Role yang mengubah data seluruh pegawai.
     *
     * @var list<int>
     */
    public const ROLE_UBAH_SEMUA = [Role::SUPER_ADMIN];

    private const TABEL_PMJ = 'pegawai_mutasi_jabatan';

    public function __construct(private readonly ?ConnectionInterface $db = null)
    {
    }

    public function bolehLihat(AuthContext $auth, string $nip): bool
    {
        $role = $this->role($auth);

        if ($role === null || $nip === '') {
            return false;
        }

        if (in_array($role, self::ROLE_LIHAT_SEMUA, true)) {
            return true;
        }

        return $this->dalamLingkupTerbatas($auth, $role, $nip);
    }

    public function bolehUbah(AuthContext $auth, string $nip): bool
    {
        $role = $this->role($auth);

        if ($role === null || $nip === '') {
            return false;
        }

        if (in_array($role, self::ROLE_UBAH_SEMUA, true)) {
            return true;
        }

        if (in_array($role, self::ROLE_LIHAT_SEMUA, true)) {
            return false;
        }

        return $this->dalamLingkupTerbatas($auth, $role, $nip);
    }

    public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
    {
        $role = $this->role($auth);

        if ($role !== null && in_array($role, self::ROLE_LIHAT_SEMUA, true)) {
            return;
        }

        $nipSendiri = $auth->nip();

        if ($role !== null && in_array($role, Role::UL_PEGAWAI, true)) {
            $nipSendiri === null
                ? $builder->where('1 = 0', null, false)
                : $builder->where($kolomNip, $nipSendiri);

            return;
        }

        if ($role === Role::ADMIN_SATKER) {
            $lingkup = $this->lingkupAdminSatker($auth);

            if ($lingkup === null) {
                $nipSendiri === null
                    ? $builder->where('1 = 0', null, false)
                    : $builder->where($kolomNip, $nipSendiri);

                return;
            }

            [$kolom, $id] = $lingkup;

            $builder->groupStart()
                ->whereIn($kolomNip, static fn (BaseBuilder $sub): BaseBuilder => $sub->select('nip')->from(self::TABEL_PMJ)->where($kolom, $id));

            if ($nipSendiri !== null) {
                $builder->orWhere($kolomNip, $nipSendiri);
            }

            $builder->groupEnd();

            return;
        }

        $builder->where('1 = 0', null, false);
    }

    /**
     * Lingkup role 2/3/6/7 (role 1/4/5/8 sudah ditangani pemanggil).
     */
    private function dalamLingkupTerbatas(AuthContext $auth, int $role, string $nip): bool
    {
        $nipSendiri = $auth->nip();

        if ($nipSendiri !== null && $nipSendiri === $nip) {
            return in_array($role, Role::UL_PEGAWAI, true) || $role === Role::ADMIN_SATKER;
        }

        if ($role !== Role::ADMIN_SATKER) {
            return false;
        }

        $lingkup = $this->lingkupAdminSatker($auth);

        if ($lingkup === null) {
            return false;
        }

        [$kolom, $id] = $lingkup;

        return $this->koneksi()->table(self::TABEL_PMJ)
            ->where('nip', $nip)
            ->where($kolom, $id)
            ->countAllResults() > 0;
    }

    /**
     * Kolom pmj + nilai lingkup admin satker: `id_satker` bila akun punya satker, selain itu `id_unit`; null bila
     * keduanya kosong/tidak valid.
     *
     * @return array{0: string, 1: int}|null
     */
    private function lingkupAdminSatker(AuthContext $auth): ?array
    {
        $idSatker = self::keInt($auth->idSatker());

        if ($idSatker !== null) {
            return ['id_satker', $idSatker];
        }

        // Akun ber-satker yang nilainya bukan angka (data VARCHAR rusak) TIDAK jatuh ke lingkup unit: tolak.
        if ($auth->idSatker() !== null && trim($auth->idSatker()) !== '') {
            return null;
        }

        $idUnit = self::keInt($auth->idUnit());

        return $idUnit === null ? null : ['id_unit', $idUnit];
    }

    /**
     * Cast VARCHAR → INT sementara sampai DBV-009: hanya angka kanonik positif ('12', bukan '012', '12a', '').
     */
    private static function keInt(?string $nilai): ?int
    {
        if ($nilai === null) {
            return null;
        }

        $nilai = trim($nilai);

        if ($nilai === '' || ! ctype_digit($nilai) || (string) (int) $nilai !== $nilai || (int) $nilai <= 0) {
            return null;
        }

        return (int) $nilai;
    }

    private function role(AuthContext $auth): ?int
    {
        if (! $auth->isAuthenticated()) {
            return null;
        }

        $role = $auth->role();

        return $role !== null && Role::isValid($role) ? $role : null;
    }

    private function koneksi(): ConnectionInterface
    {
        return $this->db ?? db_connect();
    }
}
