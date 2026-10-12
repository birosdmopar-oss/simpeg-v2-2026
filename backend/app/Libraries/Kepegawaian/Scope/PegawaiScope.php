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
 * | 3 Admin Satker             | `validateAccess_AP` atas pmj            | sama dengan lihat       |
 *
 * Role 3 = port `validateAccess_AP` [K] (`helpers/function_helper.php:72-98`, konstanta `config/constants.php`) atas
 * snapshot jabatan `pegawai_mutasi_jabatan` (pmj):
 *  - admin punya satker → `pmj.id_satker` = satker admin;
 *  - admin tanpa satker, unit UNIT_DESTINASI (21) → `pmj.id_unit = 21 OR (pmj.id_unit = UNIT_LAIN (7) AND
 *    pmj.id_satker IN SATKER_BPO (37, 38, 41))`;
 *  - admin tanpa satker, unit lain → `pmj.id_unit` = unit admin.
 * Pegawai tanpa snapshot pmj (atau satker/unit NULL) tidak pernah cocok untuk admin BERLINGKUP (punya satker atau unit
 * valid) — sama dengan legacy. Admin tanpa satker DAN tanpa unit: lihat deviasi [V2] di bawah.
 *
 * Rujukan [K]: fungsi `validateAccess_AP` `helpers/function_helper.php:72-98` (literal `['37', '38', '41']` di :85),
 * konstanta `UNIT_DESTINASI` / `UNIT_LAIN` / `LIST_SATKER_BPO` `config/constants.php:312/314/316`. Satker akun "ada"
 * bila bukan NULL dan bukan '' (tanpa trim, legacy `$idSatker_AP != ''`); nilai yang ada tetapi bukan angka kanonik
 * ditolak, tidak jatuh ke cabang unit.
 *
 * SATU predikat (lingkupAdminSatker() → terapkanPredikat()) dipakai bolehLihat()/bolehUbah() dan terapkanKeQuery(),
 * jadi isi daftar = hasil cek detail.
 *
 * Deviasi [V2] (README `Libraries/Kepegawaian`):
 *  - legacy meloloskan admin role 3 tanpa satker DAN tanpa unit (`null == ''`) ke pegawai tanpa pmj atau dengan
 *    `pmj.id_unit` NULL; v2 fail-closed (admin tanpa lingkup valid → tidak ada pegawai);
 *  - isi daftar: daftar legacy (`libraries/hr/L_employee.php:6220-6239`) untuk admin unit 20 (UNIT_KELEMBAGAAN) ikut
 *    memuat pegawai unit 6 (UPT) dan untuk admin unit 21 memuat satker 37/38/41 tanpa syarat unit 7, padahal detail
 *    (`validateAccess_AP`) tidak; v2 daftar = detail = `validateAccess_AP` — menunggu konfirmasi PM.
 * Role 3 tidak mendapat pengecualian NIP sendiri (tidak ada di legacy).
 *
 * Claim `id_satker`/`id_unit` akun bertipe VARCHAR (`pengguna`, DBV-009 belum diputus) sedangkan kolom pmj INT: nilai
 * claim di-cast ke int hanya bila berupa angka kanonik; selain itu dianggap kosong (fail-closed). Penolakan bentuk
 * non-kanonik (mis. '012') = [V2] sementara sampai DBV-009.
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

    /**
     * Unit destinasi [K] (`UNIT_DESTINASI`, legacy `config/constants.php`).
     */
    public const UNIT_DESTINASI = 21;

    /**
     * Unit lain [K] (`UNIT_LAIN`) — satker BPO di bawahnya ikut lingkup admin unit destinasi.
     */
    public const UNIT_LAIN = 7;

    /**
     * Satker BPO [K] (`SATKER_BPO`) di bawah UNIT_LAIN yang masuk lingkup admin UNIT_DESTINASI.
     *
     * @var list<int>
     */
    public const SATKER_BPO = [37, 38, 41];

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

        if ($role !== null && in_array($role, Role::UL_PEGAWAI, true)) {
            $nipSendiri = $auth->nip();

            $nipSendiri === null
                ? $builder->where('1 = 0', null, false)
                : $builder->where($kolomNip, $nipSendiri);

            return;
        }

        if ($role === Role::ADMIN_SATKER) {
            $lingkup = $this->lingkupAdminSatker($auth);

            $lingkup === null
                ? $builder->where('1 = 0', null, false)
                : $builder->whereIn($kolomNip, fn (BaseBuilder $sub): BaseBuilder => $this->terapkanPredikat($sub->select('nip')->from(self::TABEL_PMJ), $lingkup));

            return;
        }

        $builder->where('1 = 0', null, false);
    }

    /**
     * Lingkup role 2/3/6/7 (role 1/4/5/8 sudah ditangani pemanggil).
     */
    private function dalamLingkupTerbatas(AuthContext $auth, int $role, string $nip): bool
    {
        if (in_array($role, Role::UL_PEGAWAI, true)) {
            $nipSendiri = $auth->nip();

            return $nipSendiri !== null && $nipSendiri === $nip;
        }

        if ($role !== Role::ADMIN_SATKER) {
            return false;
        }

        $lingkup = $this->lingkupAdminSatker($auth);

        if ($lingkup === null) {
            return false;
        }

        $builder = $this->koneksi()->table(self::TABEL_PMJ)->where('nip', $nip);

        return $this->terapkanPredikat($builder, $lingkup)->countAllResults() > 0;
    }

    /**
     * Lingkup admin satker (`validateAccess_AP` [K]) dari claim akun; null bila tidak ada lingkup valid (fail-closed).
     *
     * @return array{satker: int}|array{unit: int}|null
     */
    private function lingkupAdminSatker(AuthContext $auth): ?array
    {
        $idSatker = self::keInt($auth->idSatker());

        if ($idSatker !== null) {
            return ['satker' => $idSatker];
        }

        // Satker "ada" = bukan NULL dan bukan '' TANPA trim, sama dengan legacy `$idSatker_AP != ''` (string spasi
        // masuk cabang satker dan tidak cocok pmj mana pun). Satker ada tetapi bukan angka kanonik (spasi, '012',
        // data VARCHAR rusak) TIDAK jatuh ke lingkup unit: tolak.
        if ($auth->idSatker() !== null && $auth->idSatker() !== '') {
            return null;
        }

        $idUnit = self::keInt($auth->idUnit());

        return $idUnit === null ? null : ['unit' => $idUnit];
    }

    /**
     * Predikat `validateAccess_AP` atas kolom pmj `id_satker`/`id_unit` builder $builder (tabel pmj tanpa alias).
     *
     * @param array{satker: int}|array{unit: int} $lingkup
     */
    private function terapkanPredikat(BaseBuilder $builder, array $lingkup): BaseBuilder
    {
        if (isset($lingkup['satker'])) {
            return $builder->where('id_satker', $lingkup['satker']);
        }

        if ($lingkup['unit'] !== self::UNIT_DESTINASI) {
            return $builder->where('id_unit', $lingkup['unit']);
        }

        return $builder->groupStart()
            ->where('id_unit', self::UNIT_DESTINASI)
            ->orGroupStart()
            ->where('id_unit', self::UNIT_LAIN)
            ->whereIn('id_satker', self::SATKER_BPO)
            ->groupEnd()
            ->groupEnd();
    }

    /**
     * Cast VARCHAR → INT sementara sampai DBV-009: hanya angka kanonik positif ('12', bukan '012', '12a', ' 12', '').
     * Tanpa trim: spasi = bukan angka kanonik. Menolak '012' = [V2] sementara (legacy membandingkan string longgar);
     * ditinjau ulang saat DBV-009 memutus tipe kolom.
     */
    private static function keInt(?string $nilai): ?int
    {
        if ($nilai === null) {
            return null;
        }

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
