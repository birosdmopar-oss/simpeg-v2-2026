<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * G-08 — Master Hari Libur. DoD mewajibkan unit test untuk dua validasi:
 *   1. tgl_mulai <= tgl_akhir
 *   2. rentang tidak boleh overlap dengan hari libur lain yang sudah terdaftar
 *
 * Plus G-TC untuk hari libur: soft delete, toggle status, RBAC, audit.
 * Seed: 1 Jan (1 hari), 19-23 Mar (rentang), 17 Agu (1 hari) — semua 2026.
 *
 * @internal
 */
final class HariLiburTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const URI = 'api/v1/master/hari-libur';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id_jenis_libur' => 'NAS',
            'nama'           => 'Hari Libur Uji',
            'tgl_mulai'      => '2026-05-01',
            'tgl_akhir'      => '2026-05-01',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Validasi #1 — tgl_mulai <= tgl_akhir
    // ------------------------------------------------------------------

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        $result = $this->sendJson('POST', self::URI, $this->payload(['tgl_mulai' => '2026-05-10', 'tgl_akhir' => '2026-05-09']));
        $result->assertStatus(422);
        $this->assertArrayHasKey('tgl_akhir', $this->json($result)['errors']);
        $this->dontSeeInDatabase('hari_libur', ['nama' => 'Hari Libur Uji']);

        // Sama persis (1 hari) diterima — batas bawah valid.
        $this->sendJson('POST', self::URI, $this->payload(['tgl_mulai' => '2026-05-09', 'tgl_akhir' => '2026-05-09']))->assertStatus(201);
    }

    public function testInvalidDateFormatIsRejected(): void
    {
        $this->sendJson('POST', self::URI, $this->payload(['tgl_mulai' => '01-05-2026']))->assertStatus(422);
        $this->sendJson('POST', self::URI, $this->payload(['tgl_akhir' => 'bukan tanggal']))->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Validasi #2 — larangan overlap
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function overlappingRanges(): iterable
    {
        // Seed: cuti bersama 2026-03-19 s.d. 2026-03-23.
        yield 'sama persis' => ['2026-03-19', '2026-03-23'];
        yield 'di dalam' => ['2026-03-20', '2026-03-21'];
        yield 'membungkus' => ['2026-03-01', '2026-03-31'];
        yield 'menimpa awal' => ['2026-03-15', '2026-03-19'];
        yield 'menimpa akhir' => ['2026-03-23', '2026-03-25'];
        yield 'satu hari di tengah' => ['2026-03-21', '2026-03-21'];
        yield 'menimpa libur satu hari' => ['2026-08-17', '2026-08-17'];
    }

    /**
     * @dataProvider overlappingRanges
     */
    public function testOverlappingRangeIsRejected(string $mulai, string $akhir): void
    {
        $result = $this->sendJson('POST', self::URI, $this->payload(['tgl_mulai' => $mulai, 'tgl_akhir' => $akhir]));
        $result->assertStatus(422);
        $this->assertArrayHasKey('tgl_mulai', $this->json($result)['errors']);
        $this->dontSeeInDatabase('hari_libur', ['nama' => 'Hari Libur Uji']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nonOverlappingRanges(): iterable
    {
        yield 'tepat sebelum' => ['2026-03-17', '2026-03-18'];
        yield 'tepat sesudah' => ['2026-03-24', '2026-03-25'];
        yield 'bulan lain' => ['2026-06-01', '2026-06-03'];
    }

    /**
     * @dataProvider nonOverlappingRanges
     */
    public function testAdjacentRangeIsAccepted(string $mulai, string $akhir): void
    {
        $this->sendJson('POST', self::URI, $this->payload(['tgl_mulai' => $mulai, 'tgl_akhir' => $akhir]))->assertStatus(201);
        $this->seeInDatabase('hari_libur', ['nama' => 'Hari Libur Uji', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir]);
    }

    public function testUpdateChecksOverlapButIgnoresItself(): void
    {
        // Memperpanjang rentangnya sendiri (tidak bentrok dengan yang lain) → boleh.
        $this->sendJson('PUT', self::URI . '/2', ['tgl_akhir' => '2026-03-24'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => 2, 'tgl_akhir' => '2026-03-24']);

        // Digeser sampai menabrak 1 Januari → ditolak.
        $this->sendJson('PUT', self::URI . '/2', ['tgl_mulai' => '2025-12-28', 'tgl_akhir' => '2026-01-01'])->assertStatus(422);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => 2, 'tgl_mulai' => '2026-03-19']);
    }

    public function testInactiveHolidayIsIgnoredForOverlapAndReactivationIsChecked(): void
    {
        // Hapus (soft) libur 17 Agustus → tanggalnya bebas dipakai lagi.
        $this->delete(self::URI . '/3')->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => 3, 'status' => '0']);

        $this->sendJson('POST', self::URI, $this->payload([
            'nama' => 'HUT RI (baru)', 'tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17',
        ]))->assertStatus(201);

        // Mengaktifkan kembali entri lama sekarang bentrok → ditolak.
        $this->sendJson('PATCH', self::URI . '/3/status', ['status' => '1'])->assertStatus(422);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => 3, 'status' => '0']);
    }

    // ------------------------------------------------------------------
    // G-TC untuk hari libur
    // ------------------------------------------------------------------

    public function testJenisLiburMustExistAndBeActive(): void
    {
        $this->sendJson('POST', self::URI, $this->payload(['id_jenis_libur' => 'ZZZ']))->assertStatus(422);

        $this->sendJson('PATCH', 'api/v1/master/jenis-libur/KHS/status', ['status' => '0'])->assertStatus(200);
        $result = $this->sendJson('POST', self::URI, $this->payload(['id_jenis_libur' => 'KHS']));
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_jenis_libur', $this->json($result)['errors']);
    }

    public function testSoftDeleteAndAuditAndListFilters(): void
    {
        $created = $this->json($this->sendJson('POST', self::URI, $this->payload()))['data'];
        $id      = (string) $created['id_hari_libur'];

        $this->sendJson('PUT', self::URI . '/' . $id, ['nama' => 'Hari Libur Uji (ubah)'])->assertStatus(200);

        $deleted = $this->json($this->delete(self::URI . '/' . $id))['data'];
        $this->assertTrue($deleted['soft_delete']);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => $id, 'status' => '0']);

        foreach (['create', 'update', 'delete'] as $event) {
            $this->seeInDatabase('audit_logs', [
                'entity'    => 'hari_libur',
                'entity_id' => $id,
                'event'     => $event,
                'nip_actor' => '198501012010011001',
            ]);
        }

        $aktif = $this->json($this->get(self::URI, ['status' => '1']))['data'];
        $this->assertSame(3, $aktif['total']);

        $nas = $this->json($this->get(self::URI, ['id_jenis_libur' => 'NAS', 'status' => '1']))['data'];
        $this->assertSame(['Tahun Baru Masehi', 'Hari Kemerdekaan'], array_column($nas['items'], 'nama'));

        $cari = $this->json($this->get(self::URI, ['search' => 'kemerdekaan']))['data'];
        $this->assertSame(1, $cari['total']);

        $tahun = $this->json($this->get(self::URI, ['tahun' => '2025']))['data'];
        $this->assertSame(0, $tahun['total']);

        $this->get(self::URI . '/9999')->assertStatus(404);
    }

    /**
     * Kalender hari libur aktif: dipakai kalkulasi hari kerja Fase 5, terbuka untuk semua role yang login.
     */
    public function testCalendarReturnsActiveHolidaysInRangeForEveryLoggedInRole(): void
    {
        foreach (Role::all() as $role) {
            $this->asRole($role);
            $result = $this->get(self::URI . '/calendar', ['from' => '2026-01-01', 'to' => '2026-12-31']);
            $result->assertStatus(200);
            $this->assertSame(['Tahun Baru Masehi', 'Cuti Bersama Idul Fitri', 'Hari Kemerdekaan'], array_column($this->json($result)['data'], 'nama'), "role {$role}");
        }

        $this->asRole(Role::SUPER_ADMIN);
        $maret = $this->json($this->get(self::URI . '/calendar', ['from' => '2026-03-01', 'to' => '2026-03-31']))['data'];
        $this->assertSame(['Cuti Bersama Idul Fitri'], array_column($maret, 'nama'));

        // Hari libur non-aktif tidak ikut kalender.
        $this->delete(self::URI . '/1')->assertStatus(200);
        $januari = $this->json($this->get(self::URI . '/calendar', ['from' => '2026-01-01', 'to' => '2026-01-31']))['data'];
        $this->assertSame([], $januari);

        $this->withHeaders(['Authorization' => ''])->get(self::URI . '/calendar')->assertStatus(401);
    }

    public function testCrudIsSuperAdminOnly(): void
    {
        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            $this->asRole($role);
            $this->get(self::URI)->assertStatus(403);
            $this->sendJson('POST', self::URI, $this->payload())->assertStatus(403);
            $this->get(self::URI . '/1')->assertStatus(403);
            $this->sendJson('PUT', self::URI . '/1', ['nama' => 'X'])->assertStatus(403);
            $this->sendJson('PATCH', self::URI . '/1/status', ['status' => '0'])->assertStatus(403);
            $this->delete(self::URI . '/1')->assertStatus(403);
        }

        $this->withHeaders(['Authorization' => ''])->get(self::URI)->assertStatus(401);
        $this->seeInDatabase('hari_libur', ['id_hari_libur' => 1, 'status' => '1']);
    }
}
