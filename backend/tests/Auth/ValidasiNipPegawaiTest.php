<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Database\Migrations\AddFkPegawaiDiPenggunaFaqRate;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\UserService;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-015 — prasyarat FK masuk DBV-019 (`pengguna.nip` → `pegawai.nip`): NIP yang diisi saat membuat akun / menautkan
 * akun tanpa NIP (Manajemen Akun, UserService) dan saat provisioning akun pegawai (AccountProvisioner) wajib ada di
 * `pegawai.nip` → 422 errors.nip "NIP tidak terdaftar sebagai pegawai.", tanpa baris tertulis. Akun non-pegawai (NIP
 * NULL, keputusan 25-09) tetap sah. Dengan FK masuk dipasang sementara, jalur service tetap 422 (bukan 1452 → 500),
 * termasuk bila baris pegawai hilang di antara cek dan tulis.
 *
 * @internal
 */
final class ValidasiNipPegawaiTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $seed = AuthSeeder::class;

    private const SUPER_ADMIN = '198501012010011001';
    private const NIP_PEGAWAI = '200201012026011001';
    private const NIP_ASING   = '200201012026011099';
    private const PASSWORD    = 'AkunBaru2026';

    private const ERRORS = ['nip' => [PenggunaModel::NIP_TIDAK_TERDAFTAR]];

    /**
     * FK masuk yang dipasang sementara; dilepas lagi di tearDown bila test gagal di tengah.
     */
    private ?Migration $fkMasuk = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->daftarkanPegawai(self::NIP_PEGAWAI);
    }

    protected function tearDown(): void
    {
        if ($this->fkMasuk !== null) {
            $this->fkMasuk->down();
            $this->fkMasuk = null;
        }

        $this->clearAuthState();
        $this->bersihkanDataDbv010();
        parent::tearDown();
    }

    public function testCreateRejectsNipNotRegisteredAsPegawai(): void
    {
        $total = $this->db->table('pengguna')->countAllResults();

        // Role pegawai (NIP wajib) dan role non-pegawai yang mengisi NIP (opsional, tetapi bila diisi harus pegawai).
        foreach ([Role::PEGAWAI => [], Role::ADMIN_VIEW_ESELON1 => ['name' => 'Admin View', 'username' => 'admin.view.asing']] as $role => $extra) {
            $result = $this->createAkun(['nip' => self::NIP_ASING, 'user_level' => $role] + $extra);

            $result->assertStatus(422);
            $this->assertSame(self::ERRORS, $this->json($result)['errors'], "role {$role}");
        }

        $this->assertSame($total, $this->db->table('pengguna')->countAllResults());
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NIP_ASING]);
        $this->dontSeeInDatabase('pengguna', ['username' => self::NIP_ASING]);
    }

    public function testCreateAcceptsRegisteredNipAndAccountWithoutNip(): void
    {
        $this->createAkun(['nip' => self::NIP_PEGAWAI, 'user_level' => Role::PEGAWAI])->assertStatus(201);
        $this->seeInDatabase('pengguna', ['nip' => self::NIP_PEGAWAI, 'username' => self::NIP_PEGAWAI]);

        // Akun non-pegawai tanpa NIP (NULL) tidak dicek ke tabel pegawai.
        $tanpaNip = $this->createAkun(['nip' => null, 'user_level' => Role::ADMIN_VIEW_ESELON1, 'name' => 'Admin View', 'username' => 'admin.view.baru']);
        $tanpaNip->assertStatus(201);
        $this->assertNull($this->json($tanpaNip)['data']['nip']);
    }

    public function testUpdateLinkingNipRequiresRegisteredPegawai(): void
    {
        $akun = $this->buatAkunTanpaNip('akun.tautan', Role::ADMIN_VIEW_ESELON1);
        $id   = (int) $akun['id_pengguna'];
        $put  = fn (array $body) => $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, $body);

        $ditolak = $put(['nip' => self::NIP_ASING, 'name' => 'Nama Baru']);
        $ditolak->assertStatus(422);
        $this->assertSame(self::ERRORS, $this->json($ditolak)['errors']);
        $this->assertSame($akun, $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray(), 'akun tidak berubah');

        $put(['nip' => self::NIP_PEGAWAI])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'nip' => self::NIP_PEGAWAI]);
    }

    public function testProvisionerRejectsNipNotRegisteredAsPegawai(): void
    {
        $pengguna    = new PenggunaModel($this->db);
        $provisioner = new AccountProvisioner($pengguna, new PasswordVerifier($pengguna));
        $total       = $this->db->table('pengguna')->countAllResults();

        try {
            $provisioner->provisionForPegawai(['nip' => self::NIP_ASING, 'name' => 'Pegawai Asing']);
            $this->fail('NIP yang tidak ada di pegawai harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame(self::ERRORS, $e->getErrors());
        }

        $this->assertSame($total, $this->db->table('pengguna')->countAllResults());
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NIP_ASING]);

        $this->assertTrue($provisioner->provisionForPegawai(['nip' => self::NIP_PEGAWAI])['created']);
    }

    /**
     * FK masuk DBV-019 (migration yang ditahan) dipasang sementara: jalur HTTP dan service tetap 422, termasuk bila
     * cek aplikasi "lolos" lalu FK menolak tulisan (1452 diterjemahkan, disimulasikan model yang salah membaca cek
     * pertama). ALTER = DDL, jadi test ini berjalan di luar bingkai transaksi uji.
     */
    #[Group('db-isolasi-penuh')]
    public function testFkMasukAktifTetap422BukanError500(): void
    {
        // Prasyarat migration (fail-closed): semua NIP akun seed & rating terdaftar sebagai pegawai.
        $nips = array_merge(
            array_column($this->db->table('pengguna')->select('nip')->where('nip IS NOT NULL', null, false)->get()->getResultArray(), 'nip'),
            array_column($this->db->table('faq_rate')->select('nip')->get()->getResultArray(), 'nip'),
        );
        $this->daftarkanPegawai(...array_values(array_unique(array_map('strval', $nips))));

        $this->fkMasuk = $this->fkMasukInstance();
        $this->fkMasuk->up();
        $this->assertTrue($this->fkTerpasang(), 'prasyarat: FK pengguna.nip → pegawai terpasang');

        $total = $this->db->table('pengguna')->countAllResults();

        // HTTP: cek aplikasi menolak lebih dulu → 422.
        $result = $this->createAkun(['nip' => self::NIP_ASING, 'user_level' => Role::PEGAWAI]);
        $result->assertStatus(422);
        $this->assertSame(self::ERRORS, $this->json($result)['errors']);

        // Service: cek pertama salah membaca "terdaftar" → FK menolak (1452) → tetap 422, tanpa baris tertulis.
        $racy = new class ($this->db) extends PenggunaModel {
            public int $buta = 0;

            public function nipTerdaftarPegawai(string $nip): bool
            {
                if ($this->buta > 0) {
                    $this->buta--;

                    return true;
                }

                return parent::nipTerdaftarPegawai($nip);
            }
        };
        $service = new UserService($racy, new PasswordVerifier($racy), service('jwt'));
        $actor   = $this->authFor(self::SUPER_ADMIN);

        $racy->buta = 1;
        $this->assertValidationNip(fn () => $service->create($actor, [
            'nip' => self::NIP_ASING, 'password' => self::PASSWORD, 'user_level' => Role::PEGAWAI,
        ]));

        $akun       = $this->buatAkunTanpaNip('akun.tautan.fk', Role::ADMIN_VIEW_ESELON1);
        $racy->buta = 1;
        $this->assertValidationNip(fn () => $service->update($actor, (int) $akun['id_pengguna'], ['nip' => self::NIP_ASING]));
        $this->seeInDatabase('pengguna', ['id_pengguna' => $akun['id_pengguna'], 'nip' => null]);

        $provisioner = new AccountProvisioner($racy, new PasswordVerifier($racy));
        $racy->buta  = 1;
        $this->assertValidationNip(fn () => $provisioner->provisionForPegawai(['nip' => self::NIP_ASING]));

        $this->assertSame($total + 1, $this->db->table('pengguna')->countAllResults(), 'hanya akun tanpa NIP uji yang bertambah');
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NIP_ASING]);

        // NIP terdaftar tetap bisa dipakai dengan FK aktif.
        $this->createAkun(['nip' => self::NIP_PEGAWAI, 'user_level' => Role::PEGAWAI])->assertStatus(201);

        $this->fkMasuk->down();
        $this->fkMasuk = null;
        $this->assertFalse($this->fkTerpasang());
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function createAkun(array $body): TestResponse
    {
        return $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', $body + ['password' => self::PASSWORD]);
    }

    private function assertValidationNip(callable $work): void
    {
        try {
            $work();
            $this->fail('NIP yang tidak ada di pegawai harus ditolak 422');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame(self::ERRORS, $e->getErrors());
        }
    }

    private function authFor(string $nip): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims($this->claimsForNip($nip));

        return $auth;
    }

    private function fkTerpasang(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            ['fk_id_pegawai_pengguna_to_pegawai'],
        )->getRowArray() !== null;
    }

    /**
     * Migration FK masuk yang ditahan (di luar app/Database/Migrations, jadi dimuat manual; pola NipReferenceRegistryTest).
     */
    private function fkMasukInstance(): AddFkPegawaiDiPenggunaFaqRate
    {
        $file = APPPATH . 'Database/MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate.php';

        if (! is_file($file)) {
            throw new RuntimeException("File migration ditahan {$file} tidak ditemukan.");
        }

        require_once $file;

        return new AddFkPegawaiDiPenggunaFaqRate();
    }
}
