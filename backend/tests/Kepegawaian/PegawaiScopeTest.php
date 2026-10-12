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
 * MAKE-009 — PegawaiScope nyata (DoD M1: lingkup role 2/3/4/5/8 + UL_PEGAWAI; role 3 = validateAccess_AP termasuk unit
 * destinasi 21 / unit lain 7). Setiap kasus juga memastikan terapkanKeQuery() menyaring daftar persis sama dengan
 * bolehLihat().
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

        // Pegawai tanpa satker/unit di snapshot jabatan: tidak cocok predikat validateAccess_AP mana pun (sama dengan legacy).
        $this->nip['tanpa_satker'] = $this->buatPegawai([], ['id_satker' => null, 'id_unit' => null, 'satker' => null, 'unit' => null]);
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
        $auth = $this->authAkunDi($this->satkerB, $role);

        foreach ($this->nip as $label => $nip) {
            $this->assertTrue($this->scope->bolehLihat($auth, $nip), "lihat {$label}");
            $this->assertSame($role === Role::SUPER_ADMIN, $this->scope->bolehUbah($auth, $nip), "ubah {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
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
        $auth = $this->authUntukAkun($this->buatAkunUntuk($this->nip['a1'], $role));

        foreach ($this->nip as $label => $nip) {
            $sendiri = $label === 'a1';
            $this->assertSame($sendiri, $this->scope->bolehLihat($auth, $nip), "lihat {$label}");
            $this->assertSame($sendiri, $this->scope->bolehUbah($auth, $nip), "ubah {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testUlPegawaiTanpaNipTidakMelihatApaPun(): void
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '999999', 'role' => Role::PEGAWAI, 'nip' => null]);

        $this->assertFalse($this->scope->bolehLihat($auth, $this->nip['a1']));
        $this->assertSame([], $this->nipTerfilter($auth));
    }

    public function testAdminSatkerTerbatasSatkernya(): void
    {
        $auth = $this->authAkunDi($this->satkerA1, Role::ADMIN_SATKER);

        foreach (['a1' => true, 'a1_kedua' => true, 'a2' => false, 'b' => false, 'tanpa_satker' => false] as $label => $harap) {
            $this->assertSame($harap, $this->scope->bolehLihat($auth, $this->nip[$label]), "lihat {$label}");
            $this->assertSame($harap, $this->scope->bolehUbah($auth, $this->nip[$label]), "ubah {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testAdminSatkerTanpaSatkerMemakaiUnit(): void
    {
        // Admin di unit A tanpa satker (legacy: lingkup unit) — satker A1 dan A2, bukan B.
        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) $this->unitA, 'id_satker' => null]);

        foreach (['a1' => true, 'a1_kedua' => true, 'a2' => true, 'b' => false, 'tanpa_satker' => false] as $label => $harap) {
            $this->assertSame($harap, $this->scope->bolehLihat($auth, $this->nip[$label]), "lihat {$label}");
            $this->assertSame($harap, $this->scope->bolehUbah($auth, $this->nip[$label]), "ubah {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testAdminSatkerTidakMendapatPengecualianNipSendiri(): void
    {
        // Admin ber-NIP yang akunnya menunjuk satker A1 sementara snapshot jabatannya di satker B: legacy tidak punya
        // pengecualian NIP sendiri untuk role 3 — dirinya di luar lingkup.
        $nipAdmin = $this->buatPegawaiDiSatker($this->satkerB);
        $auth     = $this->authUntukAkun($this->buatAkunUntuk($nipAdmin, Role::ADMIN_SATKER, ['id_satker' => (string) $this->satkerA1]));

        $this->nip['admin'] = $nipAdmin;

        $this->assertFalse($this->scope->bolehLihat($auth, $nipAdmin));
        $this->assertFalse($this->scope->bolehUbah($auth, $nipAdmin));
        $this->assertTrue($this->scope->bolehLihat($auth, $this->nip['a1']));
        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testAdminUnitDestinasiTanpaSatkerMelihatUnit21DanSatkerBpoUnit7(): void
    {
        $this->buatPegawaiUnitDestinasi();

        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_DESTINASI, 'id_satker' => null]);

        $harap = [
            'u21'             => true,
            'u7_bpo37'        => true,
            'u7_bpo38'        => true,
            'u7_bpo41'        => true,
            'u7_bukan_bpo'    => false,
            'bpo37_unit_lain' => false, // satker 37 dengan unit selain 7
            'a1'              => false,
            'b'               => false,
            'tanpa_satker'    => false,
        ];

        foreach ($harap as $label => $boleh) {
            $this->assertSame($boleh, $this->scope->bolehLihat($auth, $this->nip[$label]), "lihat {$label}");
            $this->assertSame($boleh, $this->scope->bolehUbah($auth, $this->nip[$label]), "ubah {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testAdminUnitLainTanpaSatkerHanyaUnit7(): void
    {
        $this->buatPegawaiUnitDestinasi();

        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_LAIN, 'id_satker' => null]);

        foreach (['u21' => false, 'u7_bpo37' => true, 'u7_bukan_bpo' => true, 'bpo37_unit_lain' => false, 'a1' => false] as $label => $boleh) {
            $this->assertSame($boleh, $this->scope->bolehLihat($auth, $this->nip[$label]), "lihat {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
    }

    public function testAdminBersatkerDiUnitDestinasiHanyaSatkernya(): void
    {
        $this->buatPegawaiUnitDestinasi();

        // Satker akun menang atas unit: hanya pmj.id_satker = 37, berapa pun unit snapshot-nya.
        $auth = $this->authAkunDi($this->satkerB, Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_DESTINASI, 'id_satker' => '37']);

        foreach (['u21' => false, 'u7_bpo37' => true, 'u7_bpo38' => false, 'bpo37_unit_lain' => true, 'b' => false] as $label => $boleh) {
            $this->assertSame($boleh, $this->scope->bolehLihat($auth, $this->nip[$label]), "lihat {$label}");
        }

        $this->assertDaftarSamaDenganLihat($auth);
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
        ];
    }

    #[DataProvider('lingkupAdminTidakValidProvider')]
    public function testAdminSatkerTanpaLingkupValidFailClosed(?string $idSatker, ?string $idUnit): void
    {
        if ($idUnit === '1') {
            $idUnit = (string) $this->unitA;
        }

        $auth = new AuthContext();
        $auth->setClaims(['sub' => '999999', 'role' => Role::ADMIN_SATKER, 'nip' => null, 'id_satker' => $idSatker, 'id_unit' => $idUnit]);

        foreach ($this->nip as $label => $nip) {
            $this->assertFalse($this->scope->bolehLihat($auth, $nip), "lihat {$label}");
            $this->assertFalse($this->scope->bolehUbah($auth, $nip), "ubah {$label}");
        }

        $this->assertSame([], $this->nipTerfilter($auth));
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
            $this->assertSame([], $this->nipTerfilter($auth));
        }

        $admin = $this->authAkunDi($this->satkerA1, Role::SUPER_ADMIN);
        $this->assertFalse($this->scope->bolehLihat($admin, ''), 'NIP kosong');
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
     * Pegawai fixture unit destinasi 21 / unit lain 7 (`validateAccess_AP` [K]); label => NIP, ditambahkan ke $this->nip.
     *
     * @return array<string, string>
     */
    private function buatPegawaiUnitDestinasi(): array
    {
        $this->pastikanUnit(PegawaiScope::UNIT_DESTINASI);
        $this->pastikanUnit(PegawaiScope::UNIT_LAIN);

        foreach (PegawaiScope::SATKER_BPO as $idSatker) {
            $this->pastikanSatker($idSatker, PegawaiScope::UNIT_LAIN);
        }

        $satker21        = $this->buatSatker(PegawaiScope::UNIT_DESTINASI);
        $satker7BukanBpo = $this->buatSatker(PegawaiScope::UNIT_LAIN);

        $baru = [
            'u21'             => $this->buatPegawai([], ['id_satker' => $satker21, 'id_unit' => PegawaiScope::UNIT_DESTINASI]),
            'u7_bpo37'        => $this->buatPegawai([], ['id_satker' => 37, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bpo38'        => $this->buatPegawai([], ['id_satker' => 38, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bpo41'        => $this->buatPegawai([], ['id_satker' => 41, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'u7_bukan_bpo'    => $this->buatPegawai([], ['id_satker' => $satker7BukanBpo, 'id_unit' => PegawaiScope::UNIT_LAIN]),
            'bpo37_unit_lain' => $this->buatPegawai([], ['id_satker' => 37, 'id_unit' => $this->unitA]),
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

    private function assertDaftarSamaDenganLihat(AuthContext $auth): void
    {
        $harap = array_values(array_filter($this->nip, fn (string $nip): bool => $this->scope->bolehLihat($auth, $nip)));
        sort($harap);

        $this->assertSame($harap, $this->nipTerfilter($auth), 'terapkanKeQuery() = bolehLihat()');
    }
}
