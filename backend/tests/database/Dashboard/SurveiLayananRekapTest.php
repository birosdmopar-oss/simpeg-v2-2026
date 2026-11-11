<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-11 rekap SKL (MAKE-084, role 1): kolom legacy, label jenis, unit/satker snapshot tanpa baris ganda, filter jenis/rate/
 * periode WIB/cari, teks apa adanya, XLSX + audit tanpa isi kritik/saran, 403 role lain. Data uji sintetis (tanpa data
 * pribadi asli).
 *
 * @internal
 */
final class SurveiLayananRekapTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->admin = $this->buatAkunTanpaNip('admin.skl.rekap', Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testDaftarDanFilter(): void
    {
        $a = $this->buatPegawai(['nama' => 'Ani Uji']);
        $b = $this->buatPegawai(['nama' => 'Budi Uji']);
        $this->skl($a, 1, 1, 5, '<b>uji</b>', '2026-11-10 18:00:00');
        $this->skl($a, 3, 2, 2, null, '2026-10-01 00:00:00');
        $this->skl($b, 1, 3, 4, null, '2026-09-01 00:00:00');

        $semua = $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan'))['data'];
        $this->assertSame(3, $semua['total']);
        $this->assertSame([1, 3, 1], array_column($semua['items'], 'jenis_rwy'), 'urut tanggal penilaian terbaru');
        $pertama = $semua['items'][0];
        $this->assertSame(['Cuti', $a, 5, '<b>uji</b>', '2026-11-11 01:00:00'], [$pertama['jenis_layanan'], $pertama['nip'], $pertama['rate'], $pertama['kritik_saran'], $pertama['dinilai_at']]);

        $this->assertSame(2, $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan?jenis_rwy=1'))['data']['total']);
        $this->assertSame(1, $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan?rate=2'))['data']['total']);
        $this->assertSame(1, $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan?dari=2026-11-11&sampai=2026-11-11'))['data']['total'], 'tanggal WIB');
        $this->assertSame(1, $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan?q=Budi'))['data']['total']);
        $this->asUser($this->admin)->get('api/v1/survei-layanan?jenis_rwy=9')->assertStatus(422);
        $this->asUser($this->admin)->get('api/v1/survei-layanan?rate=0')->assertStatus(422);
    }

    public function testXlsxDanAuditTanpaKritik(): void
    {
        $a = $this->buatPegawai();
        $this->skl($a, 2, 1, 4, 'rahasia-uji', '2026-11-10 00:00:00');

        $hasil = $this->asUser($this->admin)->get('api/v1/survei-layanan?format=xlsx&jenis_rwy=2');

        $hasil->assertStatus(200);
        $this->assertStringStartsWith('PK', (string) $hasil->response()->getBody());
        $audit = $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->where('entity_id', 'survei_layanan')->get()->getRowArray();
        $this->assertIsArray($audit);
        $this->assertStringNotContainsString('rahasia-uji', (string) $audit['after_json']);
        $this->assertSame(1, json_decode((string) $audit['after_json'], true)['jumlah_baris']);
    }

    public function testRoleLainDitolak403(): void
    {
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::PEGAWAI);

        $this->asNip($nip)->get('api/v1/survei-layanan')->assertStatus(403);
    }

    private function skl(string $nip, int $jenis, int $idRwy, int $rate, ?string $kritik, string $utc): void
    {
        $this->db->table('skl')->insert(['nip' => $nip, 'jenis_rwy' => $jenis, 'id_rwy' => $idRwy, 'rate' => $rate, 'kritik_saran' => $kritik, 'created_at' => $utc]);
    }
}
