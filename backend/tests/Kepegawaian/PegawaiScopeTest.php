<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-009 + CR-045 — PegawaiScope nyata (DoD M1: lingkup role 2/3/4/5/8 + UL_PEGAWAI). Role 3 tanpa satker memakai
 * predikat per jalur ikut legacy (keputusan user 12-10-2026): daftar (terapkanKeQuery), detail/ubah (bolehLihat,
 * bolehUbah = validateAccess_AP), proses (bolehProses). Setiap kasus memeriksa keempat jalur per pegawai fixture.
 *
 * @internal
 */
final class PegawaiScopeTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private PegawaiScope $scope;

    private int $unitA;

    private int $satkerA1;

    private int $satkerA2;

    private int $satkerB;

    /**
     * @var array<string, string> label => NIP
     */
    private array $nip = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scope = new PegawaiScope($this->db);

        // Unit/satker [K] dipasang lebih dulu dengan id eksplisit supaya id AUTO_INCREMENT fixture lain (> 41) tidak
        // pernah bertabrakan dengan 6/7/20/21/37/38/41.
        foreach ([PegawaiScope::UNIT_UPT, PegawaiScope::UNIT_LAIN, PegawaiScope::UNIT_KELEMBAGAAN, PegawaiScope::UNIT_DESTINASI] as $idUnit) {
            $this->pastikanUnit($idUnit);
        }

        foreach (PegawaiScope::SATKER_BPO as $idSatker) {
            $this->pastikanSatker($idSatker, PegawaiScope::UNIT_LAIN);
        }

        $this->unitA    = $this->buatUnit();
        $this->satkerA1 = $this->buatSatker($this->unitA);
        $this->satkerA2 = $this->buatSatker($this->unitA);
        $this->satkerB  = $this->buatSatker();

        $this->nip = [
            'a1'       => $this->buatPegawaiDiSatker($this->satkerA1),
            'a1_kedua' => $this->buatPegawaiDiSatker($this->satkerA1),
            'a2'       => $this->buatPegawaiDiSatker($this->satkerA2),
            'b'        => $this->buatPegawaiDiSatker($this->satkerB),
        ];

        // Pegawai tanpa satker/unit di snapshot jabatan, dan pegawai tanpa baris pmj sama sekali: tidak cocok predikat
        // jalur mana pun untuk role 3 (fail-closed; jalur proses legacy meloloskannya = deviasi [V2]).
        $this->nip['tanpa_satker'] = $this->buatPegawai([], ['id_satker' => null, 'id_unit' => null, 'satker' => null, 'unit' => null]);
        $this->nip['tanpa_pmj']    = $this->buatPegawai();
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nip['tanpa_pmj'])->delete();
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function roleLihatSemuaProvider(): array
    {
        return [
            'role 1 super admin' => [Role::SUPER_ADMIN],
            'role 4 admin view'  => [Role::ADMIN_VIEW_ESELON1],
            'role 5 menteri'     => [Role::MENTERI],
            'role 8 pimpinan'    => [Role::PIMPINAN],
        ];
    }

    #[DataProvider('roleLihatSemuaProvider')]
    public function testRoleLintasSatkerMelihatSemua(int $role): void
    {
        $auth  = $this->authAkunDi($this->satkerB, $role);
        $ubah  = $role === Role::SUPER_ADMIN;
        $harap = array_map(static fn (): array => [true, true, $ubah, $ubah], $this->nip);

        $this->assertJalur($auth, $harap);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function rolePegawaiProvider(): array
    {
        return [
            'role 2 pegawai' => [Role::PEGAWAI],
            'role 6 PTT'     => [Role::PTT],
            'role 7 PPPK'    => [Role::PPPK],
        ];
    }

    #[DataProvider('rolePegawaiProvider')]
    public function testUlPegawaiHanyaNipSendiri(int $role): void
    {
        $auth  = $this->authUntukAkun($this->buatAkunUntuk($this->nip['a1'], $role));
        $harap = [];

        foreach (array_keys($this->nip) as $label) {
            $sendiri       = $label === 'a1';
            $harap[$label] = [$sendiri, $sendiri, $sendiri, $sendiri];
        }

        $this->assertJalur($auth, $harap);
    }

    public function testUlPegawaiTanpaNipTidakMelihatApaPun(): void
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '999999', 'role' => Role::PEGAWAI, 'nip' => null]);

        $this->assertFalse($this->scope->bolehLihat($auth, $this->nip['a1']));
        $this->assertFalse($this->scope->bolehProses($auth, $this->nip['a1']));
        $this->assertSame([], $this->nipTerfilter($auth));
    }

    public function testAdminSatkerTerbatasSatkernya(): void
    {
        $auth = $this->authAkunDi($this->satkerA1, Role::ADMIN_SATKER);

        $this->assertJalur($auth, $this->semuaJalur(['a1', 'a1_kedua']));
    }

    public function testAdminSatkerTanpaSatkerMemakaiUnit(): void
    {
        // Admin di unit A tanpa satker (legacy: lingkup unit) — satker A1 dan A2, bukan B; keempat jalur sama.
        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) $this->unitA, 'id_satker' => null]);

        $this->assertJalur($auth, $this->semuaJalur(['a1', 'a1_kedua', 'a2']));
    }

    public function testAdminSatkerTidakMendapatPengecualianNipSendiri(): void
    {
        // Admin ber-NIP yang akunnya menunjuk satker A1 sementara snapshot jabatannya di satker B: legacy tidak punya
        // pengecualian NIP sendiri untuk role 3 — dirinya di luar lingkup.
        $nipAdmin = $this->buatPegawaiDiSatker($this->satkerB);
        $auth     = $this->authUntukAkun($this->buatAkunUntuk($nipAdmin, Role::ADMIN_SATKER, ['id_satker' => (string) $this->satkerA1]));

        $this->nip['admin'] = $nipAdmin;

        $this->assertJalur($auth, $this->semuaJalur(['a1', 'a1_kedua']));
    }

    /**
     * CR-045: admin unit 21 tanpa satker. Daftar & proses = `id_unit = 21 OR id_satker IN BPO` (legacy L_employee
     * "APPROVAL BPO", L_notification::get_na_ap_unit, rwy/*::process); detail/ubah = validateAccess_AP (satker BPO
     * hanya bila unit 7).
     */
    public function testAdminUnitDestinasiTanpaSatkerPerJalur(): void
    {
        $this->buatPegawaiUnitDestinasi();

        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_DESTINASI, 'id_satker' => null]);

        $this->assertJalur($auth, [
            // label             => [daftar, lihat, ubah, proses]
            'u21'             => [true, true, true, true],
            'u7_bpo37'        => [true, true, true, true],
            'u7_bpo38'        => [true, true, true, true],
            'u7_bpo41'        => [true, true, true, true],
            'u7_bukan_bpo'    => [false, false, false, false], // unit 7 satker lain
            'bpo37_unit_lain' => [true, false, false, true],   // satker BPO, unit bukan 7
            'bpo38_unit_lain' => [true, false, false, true],
            'u20'             => [false, false, false, false],
            'u6_upt'          => [false, false, false, false],
            'a1'              => [false, false, false, false],
            'a1_kedua'        => [false, false, false, false],
            'a2'              => [false, false, false, false],
            'b'               => [false, false, false, false],
            'tanpa_satker'    => [false, false, false, false],
            'tanpa_pmj'       => [false, false, false, false],
        ]);
    }

    /**
     * CR-045: admin unit 20 (UNIT_KELEMBAGAAN) tanpa satker. Daftar = `id_unit IN (20, 6)` (legacy "APPROVAL UPT");
     * detail/ubah dan proses hanya unit 20 (validateAccess_AP, get_na_ap_unit, rwy/*::process).
     */
    public function testAdminUnitKelembagaanTanpaSatkerPerJalur(): void
    {
        $this->buatPegawaiUnitDestinasi();

        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_KELEMBAGAAN, 'id_satker' => null]);

        $harap = array_map(static fn (): array => [false, false, false, false], $this->nip);

        $harap['u20']    = [true, true, true, true];
        $harap['u6_upt'] = [true, false, false, false];

        $this->assertJalur($auth, $harap);
    }

    public function testAdminUnitLainTanpaSatkerHanyaUnit7(): void
    {
        $this->buatPegawaiUnitDestinasi();

        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_LAIN, 'id_satker' => null]);

        $this->assertJalur($auth, $this->semuaJalur(['u7_bpo37', 'u7_bpo38', 'u7_bpo41', 'u7_bukan_bpo']));
    }

    public function testAdminBersatkerDiUnitDestinasiHanyaSatkernya(): void
    {
        $this->buatPegawaiUnitDestinasi();

        // Satker akun menang atas unit: semua jalur hanya pmj.id_satker = 37, berapa pun unit snapshot-nya.
        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_DESTINASI, 'id_satker' => '37']);

        $this->assertJalur($auth, $this->semuaJalur(['u7_bpo37', 'bpo37_unit_lain']));
    }

    public function testAdminBersatkerDiUnitKelembagaanHanyaSatkernya(): void
    {
        $nip = $this->buatPegawaiUnitDestinasi();

        $idSatkerUpt = (int) $this->db->table('pegawai_mutasi_jabatan')->select('id_satker')->where('nip', $nip['u6_upt'])->get()->getRow('id_satker');
        $auth        = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_KELEMBAGAAN, 'id_satker' => (string) $idSatkerUpt]);

        $this->assertJalur($auth, $this->semuaJalur(['u6_upt']));
    }

    /**
     * Satker berisi spasi: legacy `'  ' != ''` → cabang satker (tidak cocok pmj mana pun), TIDAK jatuh ke lingkup unit.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function lingkupAdminTidakValidProvider(): array
    {
        return [
            'tanpa satker dan unit'      => [null, null],
            'satker kosong, unit kosong' => ['', ''],
            'satker bukan angka'         => ['S01', null],
            'satker bukan angka + unit'  => ['S01', '1'],
            'satker berawalan nol'       => ['01', null],
            'unit bukan angka'           => [null, 'U01'],
            'satker spasi + unit'        => ['  ', '1'],
            'satker spasi'               => ['  ', null],
            'satker spasi + unit 21'     => ['  ', '21'],
            'satker spasi + unit 20'     => ['  ', '20'],
        ];
    }

    #[DataProvider('lingkupAdminTidakValidProvider')]
    public function testAdminSatkerTanpaLingkupValidFailClosed(?string $idSatker, ?string $idUnit): void
    {
        $this->buatPegawaiUnitDestinasi();

        if ($idUnit === '1') {
            $idUnit = (string) $this->unitA;
        }

        $auth = new AuthContext();
        $auth->setClaims(['sub' => '999999', 'role' => Role::ADMIN_SATKER, 'nip' => null, 'id_satker' => $idSatker, 'id_unit' => $idUnit]);

        $this->assertJalur($auth, $this->semuaJalur([]));
    }

    public function testTanpaSesiAtauRoleTidakDikenalFailClosed(): void
    {
        $tanpaSesi = new AuthContext();

        $roleAneh = new AuthContext();
        $roleAneh->setClaims(['sub' => '1', 'role' => 99, 'nip' => $this->nip['a1']]);

        $tanpaRole = new AuthContext();
        $tanpaRole->setClaims(['sub' => '1', 'nip' => $this->nip['a1']]);

        foreach ([$tanpaSesi, $roleAneh, $tanpaRole] as $auth) {
            $this->assertFalse($this->scope->bolehLihat($auth, $this->nip['a1']));
            $this->assertFalse($this->scope->bolehUbah($auth, $this->nip['a1']));
            $this->assertFalse($this->scope->bolehProses($auth, $this->nip['a1']));
            $this->assertSame([], $this->nipTerfilter($auth));
        }

        $admin = $this->authAkunDi($this->satkerA1, Role::SUPER_ADMIN);
        $this->assertFalse($this->scope->bolehLihat($admin, ''), 'NIP kosong');
        $this->assertFalse($this->scope->bolehProses($admin, ''), 'NIP kosong');

        $adminUnit = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_DESTINASI, 'id_satker' => null]);
        $this->assertFalse($this->scope->bolehProses($adminUnit, ''), 'NIP kosong role 3');
    }

    public function testFilterMenerimaKolomBeralias(): void
    {
        $auth = $this->authAkunDi($this->satkerA1, Role::ADMIN_SATKER);

        $builder = $this->db->table('pegawai p')->select('p.nip')->whereIn('p.nip', array_values($this->nip));
        $this->scope->terapkanKeQuery($builder, $auth, 'p.nip');
        $hasil = array_column($builder->get()->getResultArray(), 'nip');
        sort($hasil);

        $harap = [$this->nip['a1'], $this->nip['a1_kedua']];
        sort($harap);
        $this->assertSame($harap, $hasil);
    }

    /**
     * Pegawai fixture unit destinasi 21 / unit lain 7 / unit kelembagaan 20 / UPT 6; label => NIP, ditambahkan ke
     * $this->nip.
     *
     * @return array<string, string>
     */
    private function buatPegawaiUnitDestinasi(): array
    {
        $satker21        = $this->buatSatker(PegawaiScope::UNIT_DESTINASI);
        $satker7BukanBpo = $this->buatSatker(PegawaiScope::UNIT_LAIN);
        $satker20        = $this->buatSatker(PegawaiScope::UNIT_KELEMBAGAAN);
        $satkerUpt       = $this->buatSatker(PegawaiScope::UNIT_UPT);

        $baru = [
            'u21'             => $this->buatPegawai([], ['id_satker' => $satker21, 'id_unit' => PegawaiScope::UNIT_DESTINASI]),
            'u7_bpo37'        => $this->buatPegawai([], ['id_satker' => 37, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bpo38'        => $this->buatPegawai([], ['id_satker' => 38, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bpo41'        => $this->buatPegawai([], ['id_satker' => 41, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bukan_bpo'    => $this->buatPegawai([], ['id_satker' => $satker7BukanBpo, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'bpo37_unit_lain' => $this->buatPegawai([], ['id_satker' => 37, 'id_unit' => $this->unitA]),
            'bpo38_unit_lain' => $this->buatPegawai([], ['id_satker' => 38, 'id_unit' => $this->unitA]),
            'u20'             => $this->buatPegawai([], ['id_satker' => $satker20, 'id_unit' => PegawaiScope::UNIT_KELEMBAGAAN]),
            'u6_upt'          => $this->buatPegawai([], ['id_satker' => $satkerUpt, 'id_unit' => PegawaiScope::UNIT_UPT]),
        ];

        $this->nip = array_merge($this->nip, $baru);

        return $baru;
    }

    private function pastikanUnit(int $idUnit): void
    {
        if ($this->db->table('unit')->where('id_unit', $idUnit)->countAllResults() === 0) {
            $this->db->table('unit')->insert(['id_unit' => $idUnit, 'unit' => "Unit Fixture {$idUnit}", 'status' => 1]);
        }
    }

    private function pastikanSatker(int $idSatker, int $idUnit): void
    {
        if ($this->db->table('satker')->where('id_satker', $idSatker)->countAllResults() === 0) {
            $this->db->table('satker')->insert(['id_satker' => $idSatker, 'id_unit' => $idUnit, 'satker' => "Satker Fixture {$idSatker}", 'status' => 1]);
        }
    }

    /**
     * Akun role $role (pegawai baru di $idSatker) dengan override kolom akun.
     *
     * @param array<string, mixed> $akun
     */
    private function authAkunDi(int $idSatker, int $role, array $akun = []): AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($idSatker), $role, $akun));
    }

    /**
     * Harapan [daftar, lihat, ubah, proses] = true untuk label $boleh, false untuk sisa $this->nip.
     *
     * @param list<string> $boleh
     *
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool}>
     */
    private function semuaJalur(array $boleh): array
    {
        $harap = [];

        foreach (array_keys($this->nip) as $label) {
            $ya            = in_array($label, $boleh, true);
            $harap[$label] = [$ya, $ya, $ya, $ya];
        }

        return $harap;
    }

    /**
     * Cek keempat jalur untuk SETIAP pegawai fixture: label => [daftar, lihat, ubah, proses]. Label $this->nip yang
     * tidak disebut $harap membuat test gagal (harapan wajib lengkap).
     *
     * @param array<string, array{0: bool, 1: bool, 2: bool, 3: bool}> $harap
     */
    private function assertJalur(AuthContext $auth, array $harap): void
    {
        $this->assertSame([], array_values(array_diff(array_keys($this->nip), array_keys($harap))), 'harapan tidak lengkap');

        $harapDaftar = [];

        foreach ($harap as $label => [$daftar, $lihat, $ubah, $proses]) {
            $nip = $this->nip[$label];
            $this->assertSame($lihat, $this->scope->bolehLihat($auth, $nip), "lihat {$label}");
            $this->assertSame($ubah, $this->scope->bolehUbah($auth, $nip), "ubah {$label}");
            $this->assertSame($proses, $this->scope->bolehProses($auth, $nip), "proses {$label}");

            if ($daftar) {
                $harapDaftar[] = $nip;
            }
        }

        sort($harapDaftar);
        $this->assertSame($harapDaftar, $this->nipTerfilter($auth), 'daftar (terapkanKeQuery)');
    }

    /**
     * NIP fixture test ini yang lolos terapkanKeQuery().
     *
     * @return list<string>
     */
    private function nipTerfilter(AuthContext $auth): array
    {
        $builder = $this->db->table('pegawai')->select('nip')->whereIn('nip', array_values($this->nip));
        $this->scope->terapkanKeQuery($builder, $auth);

        $hasil = array_map('strval', array_column($builder->get()->getResultArray(), 'nip'));
        sort($hasil);

        return $hasil;
    }
}
