<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordVerifier;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * Syarat DBV-010 D-6 (ISSUE-006, CR-019) — selama email menjadi kanal reset password, buat/ubah akun menolak email
 * duplikat: dibandingkan collation kolom `utf8mb4_unicode_ci` (huruf besar/kecil dianggap sama) di antara akun yang
 * belum dihapus, aktif maupun nonaktif. Email kosong/NULL boleh untuk banyak akun. Ubah akun hanya menilai email yang
 * berubah, sehingga duplikat lama hasil impor tetap bisa diubah field lain. AccountProvisioner membuat akun tanpa email
 * duplikat dan memberi penanda `email_skipped`.
 *
 * @internal
 */
final class EmailUnikTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const SUPER_ADMIN = '198501012010011001'; // S01
    private const ADMIN_S01   = '198703102012031003'; // role 3, S01
    private const PEGAWAI_S01 = '199002152015022002'; // S01
    private const PEGAWAI_S02 = '199505052018052006'; // S02 (PTT)
    private const NEW_NIP     = '200001012024011001';
    private const NEW_NIP_2   = '200001012024011002';
    private const NEW_NIP_3   = '200001012024011003';
    private const PASSWORD    = 'AkunBaru2026';
    private const MESSAGE     = ['Email sudah dipakai akun lain.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        // Seed tidak punya email: beri email pada akun aktif S01, akun S02, akun nonaktif, dan akun yang dihapus.
        $this->setEmail(self::PEGAWAI_S01, 'siti@example.go.id');
        $this->setEmail(self::PEGAWAI_S02, 'budi@example.go.id');
        $this->setEmail(AuthSeeder::NIP_INACTIVE, 'nonaktif@example.go.id');
        $this->setEmail(AuthSeeder::NIP_ARGON, 'terhapus@example.go.id');
        $this->db->table('pengguna')->where('nip', AuthSeeder::NIP_ARGON)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testCreateRejectsDuplicateEmailIgnoringCase(): void
    {
        $total = $this->db->table('pengguna')->countAllResults();

        // Sama persis, beda huruf besar/kecil, dan milik akun nonaktif (bisa diaktifkan lagi) → ditolak.
        foreach (['siti@example.go.id', 'SITI@Example.GO.ID', 'nonaktif@example.go.id'] as $email) {
            $result = $this->create(self::SUPER_ADMIN, self::NEW_NIP, $email);
            $result->assertStatus(422);
            $this->assertSame(['email' => self::MESSAGE], $this->json($result)['errors'], $email);
        }

        $this->assertSame($total, $this->db->table('pengguna')->countAllResults());

        // Email baru diterima, lalu tidak bisa dipakai akun berikutnya walau beda huruf besar/kecil.
        $this->create(self::SUPER_ADMIN, self::NEW_NIP, 'Baru@Example.go.id')->assertStatus(201);
        $this->seeInDatabase('pengguna', ['nip' => self::NEW_NIP, 'email' => 'Baru@Example.go.id']);

        $dup = $this->create(self::SUPER_ADMIN, self::NEW_NIP_2, 'baru@example.go.id');
        $dup->assertStatus(422);
        $this->assertSame(['email' => self::MESSAGE], $this->json($dup)['errors']);
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP_2]);
    }

    /**
     * Email kosong/NULL bukan duplikat: banyak akun boleh tanpa email (seed pun seluruhnya tanpa email).
     */
    public function testEmptyEmailIsAllowedForManyAccounts(): void
    {
        $this->create(self::SUPER_ADMIN, self::NEW_NIP, null)->assertStatus(201);
        $this->create(self::SUPER_ADMIN, self::NEW_NIP_2, '')->assertStatus(201);
        $this->create(self::SUPER_ADMIN, self::NEW_NIP_3, '   ')->assertStatus(201);

        foreach ([self::NEW_NIP, self::NEW_NIP_2, self::NEW_NIP_3] as $nip) {
            $this->seeInDatabase('pengguna', ['nip' => $nip, 'email' => null]);
        }

        // Mengosongkan email akun yang punya email juga boleh.
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S01, ['email' => ''])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['nip' => self::PEGAWAI_S01, 'email' => null]);
    }

    /**
     * Akun terhapus tidak bisa login/reset dan belum ada fitur pulihkan, jadi emailnya tidak mengunci akun baru.
     */
    public function testSoftDeletedAccountEmailIsNotCompared(): void
    {
        $this->create(self::SUPER_ADMIN, self::NEW_NIP, 'terhapus@example.go.id')->assertStatus(201);
        $this->seeInDatabase('pengguna', ['nip' => self::NEW_NIP, 'email' => 'terhapus@example.go.id']);

        // Kini email itu milik akun aktif baru → akun lain tidak bisa memakainya.
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['email' => 'TERHAPUS@example.go.id'])->assertStatus(422);
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S01, ['email' => 'lain@example.go.id'])->assertStatus(200);
    }

    public function testUpdateRejectsEmailOfAnotherAccount(): void
    {
        $id     = $this->idOf(self::PEGAWAI_S02);
        $before = $this->row($id);

        foreach (['SITI@example.go.id', 'nonaktif@example.go.id'] as $email) {
            $result = $this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['name' => 'Budi', 'email' => $email]);
            $result->assertStatus(422);
            $this->assertSame(['email' => self::MESSAGE], $this->json($result)['errors'], $email);
        }

        $this->assertSame($before, $this->row($id));
    }

    /**
     * Data impor legacy bisa sudah berbagi email (legacy tidak mengecek). Form edit selalu mengirim email, jadi email
     * yang tidak berubah (termasuk hanya beda huruf besar/kecil) tidak dinilai ulang; akun tetap bisa diubah field lain
     * dan dinonaktifkan. Mengganti ke email yang dipakai akun lain tetap ditolak.
     */
    public function testLegacyDuplicatesStayEditableWhileEmailUnchanged(): void
    {
        $this->setEmail(self::PEGAWAI_S02, 'siti@example.go.id');
        $s01 = $this->idOf(self::PEGAWAI_S01);
        $s02 = $this->idOf(self::PEGAWAI_S02);

        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['name' => 'Budi', 'email' => 'siti@example.go.id', 'user_level' => Role::PTT, 'status' => '1'])->assertStatus(200);
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S01, ['name' => 'Siti', 'email' => 'siti@example.go.id', 'user_level' => Role::PEGAWAI])->assertStatus(200);
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['email' => 'Siti@Example.go.id'])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $s02, 'name' => 'Budi', 'email' => 'Siti@Example.go.id']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $s01, 'name' => 'Siti', 'email' => 'siti@example.go.id']);

        $this->clearAuthState();
        $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->patch('api/v1/auth/users/' . $s02 . '/status', ['status' => '0'])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $s02, 'status' => '0']);

        // Ganti ke email yang dipakai akun lain → ditolak; ganti ke email baru lalu kembali ke email bersama → ditolak.
        $this->assertSame(['email' => self::MESSAGE], $this->json($this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['email' => 'nonaktif@example.go.id']))['errors']);
        $this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['email' => 'budi.baru@example.go.id'])->assertStatus(200);
        $this->assertSame(['email' => self::MESSAGE], $this->json($this->update(self::SUPER_ADMIN, self::PEGAWAI_S02, ['email' => 'siti@example.go.id']))['errors']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $s02, 'email' => 'budi.baru@example.go.id']);
    }

    /**
     * Admin Satker ditolak juga bila email dipakai akun satker lain, dengan pesan generik yang tidak menyebut pemilik
     * atau satkernya.
     */
    public function testAdminSatkerGetsGenericMessageForCrossSatkerDuplicate(): void
    {
        $created = $this->create(self::ADMIN_S01, self::NEW_NIP, 'budi@example.go.id');
        $created->assertStatus(422);
        $this->assertSame(['email' => self::MESSAGE], $this->json($created)['errors']);
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);

        $updated = $this->update(self::ADMIN_S01, self::PEGAWAI_S01, ['email' => 'BUDI@example.go.id']);
        $updated->assertStatus(422);
        $this->assertSame(['email' => self::MESSAGE], $this->json($updated)['errors']);
        $this->seeInDatabase('pengguna', ['nip' => self::PEGAWAI_S01, 'email' => 'siti@example.go.id']);
    }

    /**
     * A-09: email dari data pegawai yang sudah dipakai akun lain tidak ikut disimpan; akun tetap dibuat tanpa email
     * dengan penanda `email_skipped` untuk ditindaklanjuti admin (B-05).
     */
    public function testAccountProvisionerSkipsDuplicateEmail(): void
    {
        $pengguna    = new PenggunaModel($this->db);
        $provisioner = new AccountProvisioner($pengguna, new PasswordVerifier($pengguna));

        $dup = $provisioner->provisionForPegawai(['nip' => self::NEW_NIP, 'email' => 'SITI@example.go.id']);
        $this->assertTrue($dup['created']);
        $this->assertTrue($dup['email_skipped']);
        $this->assertNull($dup['pengguna']['email']);
        $this->seeInDatabase('pengguna', ['nip' => self::NEW_NIP, 'email' => null]);

        $unique = $provisioner->provisionForPegawai(['nip' => self::NEW_NIP_2, 'email' => 'pegawai.baru@example.go.id']);
        $this->assertFalse($unique['email_skipped']);
        $this->assertSame('pegawai.baru@example.go.id', $unique['pengguna']['email']);

        $deleted = $provisioner->provisionForPegawai(['nip' => self::NEW_NIP_3, 'email' => 'terhapus@example.go.id']);
        $this->assertFalse($deleted['email_skipped']);
        $this->assertSame('terhapus@example.go.id', $deleted['pengguna']['email']);

        $again = $provisioner->provisionForPegawai(['nip' => self::NEW_NIP, 'email' => 'SITI@example.go.id']);
        $this->assertFalse($again['created']);
        $this->assertFalse($again['email_skipped']);
    }

    // ------------------------------------------------------------------

    private function create(string $actor, string $nip, ?string $email): TestResponse
    {
        $this->clearAuthState();

        return $this->asNip($actor)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip'        => $nip,
            'email'      => $email,
            'password'   => self::PASSWORD,
            'user_level' => Role::PEGAWAI,
            'id_unit'    => 'U01',
            'id_satker'  => 'S01',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function update(string $actor, string $nip, array $body): TestResponse
    {
        $this->clearAuthState();

        return $this->asNip($actor)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->idOf($nip), $body);
    }

    private function setEmail(string $nip, ?string $email): void
    {
        $this->db->table('pengguna')->where('nip', $nip)->update(['email' => $email]);
    }

    private function idOf(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }
}
