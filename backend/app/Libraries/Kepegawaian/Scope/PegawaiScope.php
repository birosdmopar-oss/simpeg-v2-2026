<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Scope;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Lingkup akses data pegawai — implementasi nyata WS-2 (MAKE-009) untuk PegawaiScopeInterface.
 *
 * Aturan per role (Matriks v2 `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, keputusan #4/#10):
 *
 * | Role                       | Lihat                              | Ubah              | Proses (setujui/tolak) |
 * |----------------------------|------------------------------------|-------------------|------------------------|
 * | 1 Super Admin              | semua pegawai                      | semua pegawai     | = ubah                 |
 * | 4 Admin View, 5 Menteri, 8 | semua pegawai (view lintas satker) | tidak ada         | = ubah                 |
 * | 2/6/7 (UL_PEGAWAI)         | NIP sendiri (claim `nip`)          | NIP sendiri       | = ubah                 |
 * | 3 Admin Satker             | jalur detail (di bawah)            | jalur detail      | jalur proses           |
 *
 * Role 3 atas snapshot jabatan `pegawai_mutasi_jabatan` (pmj). Admin punya satker → SEMUA jalur `pmj.id_satker` =
 * satker admin. Admin tanpa satker → predikat per jalur, ikut pembagian jalur legacy [K] (keputusan user 12-10-2026,
 * CR-045):
 *
 * | Admin tanpa satker | Daftar (terapkanKeQuery)                  | Detail/ubah (bolehLihat, bolehUbah)                   | Proses (bolehProses)                      |
 * |--------------------|-------------------------------------------|-------------------------------------------------------|-------------------------------------------|
 * | unit 20            | `id_unit IN (20, 6)`                      | `id_unit = 20`                                        | `id_unit = 20`                            |
 * | unit 21            | `id_unit = 21 OR id_satker IN (37,38,41)` | `id_unit = 21 OR (id_unit = 7 AND id_satker IN (…))` | `id_unit = 21 OR id_satker IN (37,38,41)` |
 * | unit lain          | `id_unit` = unit admin                    | sama                                                  | sama                                      |
 *
 * Rujukan [K]: daftar `libraries/hr/L_employee.php:6221-6239` ("APPROVAL BPO" / "APPROVAL UPT", literal unit '6' di
 * :6232); detail `validateAccess_AP` `helpers/function_helper.php:72-98` (literal `['37', '38', '41']` di :85);
 * proses `libraries/hr/L_notification.php:1897-1901` (`get_na_ap_unit`, antrean approval) dan
 * `controllers/hr/rwy/Kp.php:462-484` (`process`, pola sama di rwy/* lain); konstanta `UNIT_DESTINASI` /
 * `UNIT_KELEMBAGAAN` / `UNIT_LAIN` / `LIST_SATKER_BPO` `config/constants.php:312-316`. Konsekuensi yang disengaja (sama
 * dengan legacy): pegawai UPT muncul di daftar admin unit 20 tetapi detailnya 403; pegawai satker BPO dengan unit bukan
 * 7 bisa diproses admin unit 21 tetapi tidak bisa dibuka/diubah.
 *
 * Satker akun "ada" bila bukan NULL dan bukan '' (tanpa trim, legacy `$idSatker_AP != ''`); nilai yang ada tetapi
 * bukan angka kanonik ditolak, tidak jatuh ke cabang unit.
 *
 * Deviasi [V2] (README `Libraries/Kepegawaian`):
 *  - legacy meloloskan admin role 3 tanpa satker DAN tanpa unit (`null == ''`) ke pegawai tanpa pmj atau dengan
 *    `pmj.id_unit` NULL; v2 fail-closed (admin tanpa lingkup valid → tidak ada pegawai);
 *  - jalur proses legacy (`rwy/*::process`) meloloskan pegawai tanpa baris pmj dan pegawai dengan `pmj.id_satker` /
 *    `pmj.id_unit` kosong; v2 fail-closed di SEMUA jalur (pegawai tanpa pmj atau satker/unit NULL tidak pernah cocok).
 * Role 3 tidak mendapat pengecualian NIP sendiri (tidak ada di legacy).
 *
 * Claim `id_satker`/`id_unit` akun bertipe VARCHAR (`pengguna`, DBV-009 belum diputus) sedangkan kolom pmj INT: nilai
 * claim di-cast ke int hanya bila berupa angka kanonik; selain itu dianggap kosong (fail-closed). Penolakan bentuk
 * non-kanonik (mis. '012') = [V2] sementara sampai DBV-009.
 */
final class PegawaiScope implements PegawaiScopeInterface, PegawaiScopeProsesInterface
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
     * Unit lain [K] (`UNIT_LAIN`) — satker BPO di bawahnya ikut lingkup detail admin unit destinasi.
     */
    public const UNIT_LAIN = 7;

    /**
     * Unit kelembagaan [K] (`UNIT_KELEMBAGAAN`) — daftar admin tanpa satker di unit ini ikut memuat UNIT_UPT.
     */
    public const UNIT_KELEMBAGAAN = 20;

    /**
     * Unit UPT [K] (literal '6' di daftar legacy "APPROVAL UPT", `libraries/hr/L_employee.php:6232`).
     */
    public const UNIT_UPT = 6;

    /**
     * Satker BPO [K] (`LIST_SATKER_BPO`) yang masuk lingkup admin UNIT_DESTINASI (detail: hanya bila unit UNIT_LAIN).
     *
     * @var list<int>
     */
    public const SATKER_BPO = [37, 38, 41];

    private const TABEL_PMJ = 'pegawai_mutasi_jabatan';

    /**
     * Jalur lingkup admin role 3 tanpa satker (tabel di docblock kelas).
     */
    private const JALUR_DAFTAR = 'daftar';

    private const JALUR_DETAIL = 'detail';

    private const JALUR_PROSES = 'proses';

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

        return $this->dalamLingkupTerbatas($auth, $role, $nip, self::JALUR_DETAIL);
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

        return $this->dalamLingkupTerbatas($auth, $role, $nip, self::JALUR_DETAIL);
    }

    public function bolehProses(AuthContext $auth, string $nip): bool
    {
        if ($this->role($auth) !== Role::ADMIN_SATKER) {
            return $this->bolehUbah($auth, $nip);
        }

        return $nip !== '' && $this->dalamLingkupTerbatas($auth, Role::ADMIN_SATKER, $nip, self::JALUR_PROSES);
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
                : $builder->whereIn($kolomNip, fn (BaseBuilder $sub): BaseBuilder => $this->terapkanPredikat($sub->select('nip')->from(self::TABEL_PMJ), $lingkup, self::JALUR_DAFTAR));

            return;
        }

        $builder->where('1 = 0', null, false);
    }

    /**
     * Jalur proses (MAKE-011, PegawaiScopeProsesInterface) = predikat `bolehProses`: role 1 semua; role 3 predikat jalur
     * proses atas pmj; UL_PEGAWAI NIP sendiri (sama dengan bolehUbah); role 4/5/8 dan lainnya tidak ada.
     */
    public function terapkanKeQueryProses(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
    {
        $role = $this->role($auth);

        if ($role !== null && in_array($role, self::ROLE_UBAH_SEMUA, true)) {
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
                : $builder->whereIn($kolomNip, fn (BaseBuilder $sub): BaseBuilder => $this->terapkanPredikat($sub->select('nip')->from(self::TABEL_PMJ), $lingkup, self::JALUR_PROSES));

            return;
        }

        $builder->where('1 = 0', null, false);
    }

    /**
     * Lingkup role 2/3/6/7 (role 1/4/5/8 sudah ditangani pemanggil); $jalur hanya berpengaruh untuk role 3 tanpa satker.
     */
    private function dalamLingkupTerbatas(AuthContext $auth, int $role, string $nip, string $jalur): bool
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

        return $this->terapkanPredikat($builder, $lingkup, $jalur)->countAllResults() > 0;
    }

    /**
     * Lingkup admin satker dari claim akun (satker menang atas unit, `validateAccess_AP` [K]); null bila tidak ada
     * lingkup valid (fail-closed).
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
     * Predikat lingkup admin role 3 jalur $jalur atas kolom pmj `id_satker`/`id_unit` builder $builder (tabel pmj tanpa
     * alias). Kolom NULL tidak pernah cocok (fail-closed).
     *
     * @param array{satker: int}|array{unit: int} $lingkup
     */
    private function terapkanPredikat(BaseBuilder $builder, array $lingkup, string $jalur): BaseBuilder
    {
        if (isset($lingkup['satker'])) {
            return $builder->where('id_satker', $lingkup['satker']);
        }

        $unit = $lingkup['unit'];

        if ($unit === self::UNIT_KELEMBAGAAN && $jalur === self::JALUR_DAFTAR) {
            // Daftar legacy "APPROVAL UPT" (L_employee.php:6231-6239); detail dan proses hanya unit 20.
            return $builder->whereIn('id_unit', [self::UNIT_KELEMBAGAAN, self::UNIT_UPT]);
        }

        if ($unit !== self::UNIT_DESTINASI) {
            return $builder->where('id_unit', $unit);
        }

        $builder->groupStart()->where('id_unit', self::UNIT_DESTINASI);

        if ($jalur === self::JALUR_DETAIL) {
            // validateAccess_AP (function_helper.php:79-87): satker BPO hanya bila unit 7.
            $builder->orGroupStart()
                ->where('id_unit', self::UNIT_LAIN)
                ->whereIn('id_satker', self::SATKER_BPO)
                ->groupEnd();
        } else {
            // Daftar (L_employee.php:6221-6229) dan proses (L_notification.php:1899-1901, rwy/Kp.php:466-470): satker
            // BPO berapa pun unitnya.
            $builder->orWhereIn('id_satker', self::SATKER_BPO);
        }

        return $builder->groupEnd();
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
