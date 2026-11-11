<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-11 ringkasan SKL (MAKE-084, Q7-26, kontrak Jalur D): lima jenis selalu ada, label dari master `jenis_layanan` lewat kolom
 * `table` (cuti tanpa baris master → "Cuti"), rata-rata 1 desimal + sebaran, periode WIB inklusif (bawaan 12 bulan),
 * 422 periode salah, 403 role lain.
 *
 * @internal
 */
final class SurveiLayananRingkasanTest extends DatabaseTestCase
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
        Time::setTestNow('2026-11-11 08:00:00', 'Asia/Jakarta');
        $this->admin = $this->buatAkunTanpaNip('admin.skl', Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRingkasanPerJenisDanTotal(): void
    {
        $nip = $this->buatPegawai();
        // Cuti: 5, 4, 4 → 4.3. KARPEG: 3. Di luar periode bawaan (lebih dari 12 bulan) tidak dihitung.
        $this->skl($nip, 1, 1, 5, '2026-11-10 03:00:00');
        $this->skl($nip, 1, 2, 4, '2026-06-01 03:00:00');
        $this->skl($nip, 1, 3, 4, '2025-11-12 00:00:00'); // = 2025-11-12 07:00 WIB, hari pertama periode
        $this->skl($nip, 3, 4, 3, '2026-01-01 00:00:00');
        $this->skl($nip, 4, 5, 1, '2025-11-11 16:59:59'); // = 2025-11-11 23:59 WIB, di luar periode

        $data = $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan/ringkasan'))['data'];

        $this->assertSame(['dari' => '2025-11-12', 'sampai' => '2026-11-11'], $data['periode']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($data['per_jenis'], 'jenis_rwy'));
        $this->assertSame(['Cuti', 'KARISKARSU', 'KARPEG', 'Tugas Belajar', 'Izin Belajar'], array_column($data['per_jenis'], 'jenis_layanan'));
        $this->assertSame([3, 0, 1, 0, 0], array_column($data['per_jenis'], 'jumlah'));
        $this->assertEqualsWithDelta(4.3, $data['per_jenis'][0]['rata_rata'], 0.001);
        $this->assertNull($data['per_jenis'][1]['rata_rata']);
        $this->assertSame(['1' => 0, '2' => 0, '3' => 0, '4' => 2, '5' => 1], $data['per_jenis'][0]['sebaran']);
        $this->assertSame(4, $data['total']['jumlah']);
        $this->assertEqualsWithDelta(4.0, $data['total']['rata_rata'], 0.001);
    }

    public function testPeriodeDariSampaiInklusifWib(): void
    {
        $nip = $this->buatPegawai();
        $this->skl($nip, 2, 1, 5, '2026-01-31 17:00:00'); // 2026-02-01 00:00 WIB
        $this->skl($nip, 2, 2, 3, '2026-01-31 16:59:59'); // 2026-01-31 23:59 WIB

        $data = $this->json($this->asUser($this->admin)->get('api/v1/survei-layanan/ringkasan?dari=2026-02-01&sampai=2026-02-28'))['data'];

        $this->assertSame([1, 5.0], [$data['per_jenis'][1]['jumlah'], (float) $data['per_jenis'][1]['rata_rata']]);
        $this->asUser($this->admin)->get('api/v1/survei-layanan/ringkasan?dari=2026-03-01&sampai=2026-02-01')->assertStatus(422);
        $this->asUser($this->admin)->get('api/v1/survei-layanan/ringkasan?dari=bukan')->assertStatus(422);
    }

    public function testRoleLainDitolak403(): void
    {
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::ADMIN_SATKER);

        $this->asNip($nip)->get('api/v1/survei-layanan/ringkasan')->assertStatus(403);
    }

    private function skl(string $nip, int $jenis, int $idRwy, int $rate, string $utc): void
    {
        $this->db->table('skl')->insert(['nip' => $nip, 'jenis_rwy' => $jenis, 'id_rwy' => $idRwy, 'rate' => $rate, 'created_at' => $utc]);
    }
}
