<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\DokumenUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-09 scope (MAKE-037, PRD §7.2.11 DokumenScopeTest, F7-22): Admin Satker hanya satker/unit akun pada JSON, PDF,
 * XLSX (jumlah baris di audit), lingkup lain → 403, NIP Baperjakat di luar lingkup → 422; role 1/4/5/8 lintas satker.
 *
 * @internal
 */
final class DokumenScopeTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DokumenUjiTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/dokumen';

    private string $nipA1;

    private string $nipA2;

    private string $nipB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->pasangThrottlerUji();
        $this->siapkanMasterDokumen();

        $this->nipA1 = $this->pegawaiDokumen('A1', ['nama' => 'Adi', 'jenis' => 1, 'lahir' => '1980-01-01', 'pangkat' => 'III/a']);
        $this->pegawaiDokumen('A1', ['nama' => 'Ani', 'jenis' => 1, 'lahir' => '1982-01-01', 'pangkat' => 'IV/a']);
        $this->nipA2 = $this->pegawaiDokumen('A2', ['nama' => 'Bayu', 'jenis' => 1, 'lahir' => '1984-01-01', 'pangkat' => 'III/a']);
        $this->nipB1 = $this->pegawaiDokumen('B1', ['nama' => 'Cici', 'jenis' => 1, 'lahir' => '1986-01-01', 'pangkat' => 'II/a']);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testAdminSatkerHanyaSatkernya(): void
    {
        $bearer = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER));
        $h      = ['Authorization' => $bearer];

        $duk = $this->dataDokumen($this->withHeaders($h)->get(self::BASE . '/duk'));
        $this->assertSame('satker', $duk['lingkup']['tipe']);
        $this->assertSame(['Ani', 'Adi'], $this->namaItems($duk), 'hanya satker A1 (IV/a lalu III/a)');

        $this->withHeaders($h)->get(self::BASE . '/duk', ['lingkup' => 'kementerian'])->assertStatus(403);
        $this->withHeaders($h)->get(self::BASE . '/nominatif', ['lingkup' => 'unit', 'id' => (string) $this->unit['A']])->assertStatus(403);
        $this->withHeaders($h)->get(self::BASE . '/duk/pdf', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']])->assertStatus(403);
        $this->withHeaders($h)->get(self::BASE . '/duk/xlsx')->assertStatus(200);

        $after = json_decode((string) $this->auditUnduhan('duk')[0]['after_json'], true);
        $this->assertSame(['satker', $this->satker['A1'], 2], [$after['lingkup'], $after['id'], $after['jumlah_baris']]);

        // Baperjakat: NIP di luar lingkup (A2, B1) → 422 menyebut NIP-nya; NIP sendiri boleh.
        $r = $this->withHeaders($h)->withBodyFormat('json')->post(self::BASE . '/baperjakat/xlsx', ['nip' => [$this->nipA1, $this->nipA2, $this->nipB1], 'judul' => 'X']);
        $r->assertStatus(422);
        $pesan = $this->json($r)['errors']['nip'][0];
        $this->assertStringContainsString($this->nipA2, $pesan);
        $this->assertStringContainsString($this->nipB1, $pesan);
        $this->assertStringNotContainsString($this->nipA1, $pesan);
        $this->withHeaders($h)->withBodyFormat('json')->post(self::BASE . '/baperjakat/xlsx', ['nip' => [$this->nipA1], 'judul' => 'X'])->assertStatus(200);
    }

    public function testAdminUnitTanpaSatkerMelihatSeluruhUnit(): void
    {
        $bearer = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER, ['id_satker' => null, 'id_unit' => (string) $this->unit['A']]));
        $h      = ['Authorization' => $bearer];

        $nom = $this->dataDokumen($this->withHeaders($h)->get(self::BASE . '/nominatif'));
        $this->assertSame('unit', $nom['lingkup']['tipe']);
        $this->assertSame(3, $nom['total'], 'A1 + A2, tanpa B1');
        $this->withHeaders($h)->get(self::BASE . '/duk', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']])->assertStatus(200);
        $this->withHeaders($h)->get(self::BASE . '/duk', ['lingkup' => 'satker', 'id' => (string) $this->satker['B1']])->assertStatus(403);
        $this->withHeaders($h)->get(self::BASE . '/duk', ['lingkup' => 'kementerian'])->assertStatus(403);
    }

    public function testRoleLintasSatkerMelihatSemua(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->assertSame(4, $this->dataDokumen($this->asRole($role)->get(self::BASE . '/duk'))['total'], "role {$role}");
        }

        $this->asRole(Role::PEGAWAI)->get(self::BASE . '/duk')->assertStatus(403);
    }
}
