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
 * MAKE-009 — PegawaiScope nyata (DoD M1: lingkup role 2/3/4/5/8 + UL_PEGAWAI). Setiap kasus juga memastikan
 * terapkanKeQuery() menyaring daftar persis sama dengan bolehLihat().
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

        // Pegawai tanpa satker di snapshot jabatan (legacy meloloskannya ke semua admin satker; v2 menolak).
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

    public function testAdminSatkerMelihatDirinyaSendiriDiLuarSatker(): void
    {
        // Admin ber-NIP yang akunnya menunjuk satker A1 sementara snapshot jabatannya di satker B.
        $nipAdmin = $this->buatPegawaiDiSatker($this->satkerB);
        $auth     = $this->authUntukAkun($this->buatAkunUntuk($nipAdmin, Role::ADMIN_SATKER, ['id_satker' => (string) $this->satkerA1]));

        $this->assertTrue($this->scope->bolehLihat($auth, $nipAdmin));
        $this->assertTrue($this->scope->bolehUbah($auth, $nipAdmin));
        $this->assertTrue($this->scope->bolehLihat($auth, $this->nip['a1']));
        $this->assertFalse($this->scope->bolehLihat($auth, $this->nip['b']));

        $builder = $this->db->table('pegawai')->select('nip')->whereIn('nip', [$nipAdmin, $this->nip['a1'], $this->nip['b']]);
        $this->scope->terapkanKeQuery($builder, $auth);
        $hasil = array_column($builder->get()->getResultArray(), 'nip');
        sort($hasil);
        $harap = [$nipAdmin, $this->nip['a1']];
        sort($harap);
        $this->assertSame($harap, $hasil);
    }

    /**
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
