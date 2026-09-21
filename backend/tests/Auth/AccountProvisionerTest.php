<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordVerifier;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-09 — provisioning akun otomatis: pegawai baru → pengguna dengan username = NIP.
 * Test integrasi penuh dengan Modul B diulang sebagai regression di akhir Fase 3 (B-05).
 *
 * @internal
 */
final class AccountProvisionerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP = '200101012025011001';

    private AccountProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $pengguna          = new PenggunaModel($this->db);
        $this->provisioner = new AccountProvisioner($pengguna, new PasswordVerifier($pengguna));
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testProvisionCreatesAccountWithUsernameEqualsNip(): void
    {
        $result = $this->provisioner->provisionForPegawai(['nip' => self::NIP, 'id_unit' => 'U01', 'id_satker' => 'S03']);

        $this->assertTrue($result['created']);
        $this->assertSame(self::NIP, $result['pengguna']['username']);
        $this->assertSame(self::NIP, $result['pengguna']['nip']);
        $this->assertSame(Role::PEGAWAI, $result['pengguna']['user_level']);
        $this->assertSame('S03', $result['pengguna']['id_satker']);
        $this->assertNotNull($result['initial_password']);

        $this->seeInDatabase('pengguna', ['nip' => self::NIP, 'username' => self::NIP, 'status' => '1', 'password_legacy' => null]);

        // Akun bisa dipakai login dengan password awal.
        $this->login(self::NIP, (string) $result['initial_password'])->assertStatus(200);

        // Audit create tercatat (actor NULL = proses sistem).
        $this->seeInDatabase('audit_logs', ['entity' => 'pengguna', 'event' => 'create', 'entity_id' => (string) $result['pengguna']['id_pengguna']]);
    }

    public function testProvisionIsIdempotent(): void
    {
        $first  = $this->provisioner->provisionForPegawai(['nip' => self::NIP]);
        $second = $this->provisioner->provisionForPegawai(['nip' => self::NIP]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertNull($second['initial_password']);
        $this->assertSame($first['pengguna']['id_pengguna'], $second['pengguna']['id_pengguna']);
        $this->assertSame(1, $this->db->table('pengguna')->where('nip', self::NIP)->countAllResults());
    }

    public function testExistingSeedAccountIsNotDuplicated(): void
    {
        $result = $this->provisioner->provisionForPegawai(['nip' => '199002152015022002']);

        $this->assertFalse($result['created']);
        $this->assertSame(1, $this->db->table('pengguna')->where('nip', '199002152015022002')->countAllResults());
    }

    public function testRoleCanBeSpecifiedForPttPppk(): void
    {
        $result = $this->provisioner->provisionForPegawai(['nip' => self::NIP], Role::PPPK);

        $this->assertSame(Role::PPPK, $result['pengguna']['user_level']);
    }

    public function testInvalidNipIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->provisioner->provisionForPegawai(['nip' => '12345']);
    }

    public function testGeneratedPasswordSatisfiesPolicy(): void
    {
        $verifier = new PasswordVerifier(new PenggunaModel($this->db));

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([], $verifier->policyErrors(AccountProvisioner::generatePassword()));
        }
    }
}
