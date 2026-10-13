<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordVerifier;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * A-09 — provisioning akun otomatis: pegawai baru → pengguna dengan username = NIP.
 * Test integrasi penuh dengan Modul B diulang sebagai regression di akhir Fase 3 (B-05).
 *
 * @internal
 */
final class AccountProvisionerTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP = '200101012025011001';

    private const USERNAME_TAKEN = 'NIP ini sudah dipakai sebagai username akun lain; ganti username akun tersebut sebelum membuat akun pegawai.';

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
        $this->bersihkanDataDbv010();
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

    /**
     * DBV-010 — NIP ikut legacy: angka saja, maksimal 18 digit. NIK 16 digit (Non-PNS) diterima; 19 digit, huruf, spasi,
     * dan kosong ditolak.
     */
    public function testNipFollowsLegacyRule(): void
    {
        foreach (['1234567890123456789', '19900215201502200A', '1990 0215', ''] as $nip) {
            try {
                $this->provisioner->provisionForPegawai(['nip' => $nip]);
                $this->fail("NIP '{$nip}' harus ditolak");
            } catch (ValidationException $e) {
                $this->assertSame(['nip'], array_keys($e->getErrors() ?? []));
            }
        }

        $nik    = '3171012345678901';
        $result = $this->provisioner->provisionForPegawai(['nip' => $nik, 'name' => 'Pegawai Non-PNS', 'email' => 'nonpns@example.go.id'], Role::PTT);

        $this->assertTrue($result['created']);
        $this->assertSame($nik, $result['pengguna']['nip']);
        $this->assertSame($nik, $result['pengguna']['username']);
        $this->assertSame('Pegawai Non-PNS', $result['pengguna']['name']);
        $this->assertSame('nonpns@example.go.id', $result['pengguna']['email']);
    }

    public function testNameAndEmailAreOptionalButValidated(): void
    {
        $result = $this->provisioner->provisionForPegawai(['nip' => self::NIP]);
        $this->assertNull($result['pengguna']['name']);
        $this->assertNull($result['pengguna']['email']);

        foreach ([['email' => 'bukan-email'], ['name' => str_repeat('n', 151)]] as $extra) {
            try {
                $this->provisioner->provisionForPegawai(['nip' => '200101012025011002'] + $extra);
                $this->fail('Harus ditolak: ' . json_encode($extra));
            } catch (ValidationException $e) {
                $this->assertSame(array_keys($extra), array_keys($e->getErrors() ?? []));
            }
        }

        $this->dontSeeInDatabase('pengguna', ['nip' => '200101012025011002']);
    }

    /**
     * ISSUE-023 — NIP pegawai baru sudah dipakai sebagai username bebas akun lain (akun non-pegawai tanpa NIP, aktif
     * maupun sudah dihapus; UNIQUE username mencakup baris soft-deleted). Sebelumnya insert gagal 1062 → 500. Sekarang
     * 422 errors.nip berpesan jelas: akun tidak dibuat, tidak ditautkan ke akun itu, dan akun itu tidak berubah.
     */
    public function testNipAlreadyUsedAsUsernameOfAnotherAccountIsRejected(): void
    {
        foreach (['aktif' => null, 'terhapus' => date('Y-m-d H:i:s')] as $label => $deletedAt) {
            $other = $this->buatAkunTanpaNip(self::NIP, Role::ADMIN_VIEW_ESELON1, ['deleted_at' => $deletedAt]);
            $total = $this->db->table('pengguna')->countAllResults();

            try {
                $this->provisioner->provisionForPegawai(['nip' => self::NIP, 'name' => 'Pegawai Baru']);
                $this->fail("akun {$label}: provisioning harus ditolak");
            } catch (ValidationException $e) {
                $this->assertSame(['nip' => [self::USERNAME_TAKEN]], $e->getErrors(), $label);
            }

            $this->assertSame($total, $this->db->table('pengguna')->countAllResults(), $label);
            $this->assertSame($other, $this->db->table('pengguna')->where('id_pengguna', $other['id_pengguna'])->get()->getRowArray(), $label);
            $this->dontSeeInDatabase('pengguna', ['nip' => self::NIP]);

            $this->db->table('pengguna')->where('id_pengguna', $other['id_pengguna'])->delete();
        }
    }

    /**
     * Balapan cek-lalu-tulis (disimulasikan model yang "tidak melihat" baris lain pada cek awal):
     * - proses lain lebih dulu membuat akun untuk NIP yang sama → tetap idempoten (created=false, akun yang sudah ada);
     * - akun lain memakai username = NIP di sela cek dan insert → 422 errors.nip yang sama, bukan 1062 → 500.
     */
    public function testRaceIsIdempotentForSameNipAndRejectsUsernameTakenMeanwhile(): void
    {
        $racy = new class ($this->db) extends PenggunaModel {
            public int $blind = 0;

            public function first()
            {
                $row = parent::first();

                if ($this->blind > 0) {
                    $this->blind--;

                    return null;
                }

                return $row;
            }

            public function countAllResults(bool $reset = true, bool $test = false)
            {
                $count = parent::countAllResults($reset, $test);

                if ($this->blind > 0) {
                    $this->blind--;

                    return 0;
                }

                return $count;
            }
        };
        $provisioner = new AccountProvisioner($racy, new PasswordVerifier($racy));

        $first       = $this->provisioner->provisionForPegawai(['nip' => self::NIP]);
        $racy->blind = 2;
        $second      = $provisioner->provisionForPegawai(['nip' => self::NIP]);

        $this->assertFalse($second['created']);
        $this->assertNull($second['initial_password']);
        $this->assertSame($first['pengguna']['id_pengguna'], $second['pengguna']['id_pengguna']);
        $this->assertSame(1, $this->db->table('pengguna')->where('nip', self::NIP)->countAllResults());

        $nip   = '200101012025011002';
        $other = $this->buatAkunTanpaNip($nip, Role::PIMPINAN);
        $total = $this->db->table('pengguna')->countAllResults();

        $racy->blind = 2;

        try {
            $provisioner->provisionForPegawai(['nip' => $nip]);
            $this->fail('username = NIP yang dipakai akun lain harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(['nip' => [self::USERNAME_TAKEN]], $e->getErrors());
        }

        $this->assertSame($total, $this->db->table('pengguna')->countAllResults());
        $this->assertSame($other, $this->db->table('pengguna')->where('id_pengguna', $other['id_pengguna'])->get()->getRowArray());
    }

    public function testGeneratedPasswordSatisfiesPolicy(): void
    {
        $verifier = new PasswordVerifier(new PenggunaModel($this->db));

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([], $verifier->policyErrors(AccountProvisioner::generatePassword()));
        }
    }
}
