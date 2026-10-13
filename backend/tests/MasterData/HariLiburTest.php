<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\MasterData\HariLiburService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * G-08 — hari libur (DBV-003/CR-010, keputusan F1-F6): RBAC baca 1/4/5/8 & tulis 1, validasi tanggal/rentang/jenis,
 * overlap inklusif terhadap SEMUA status di dalam transaksi + named lock, terjemahan 1062/CHECK ke 422, soft delete,
 * dan tanggalLibur() (hanya status 1).
 *
 * Seed (MasterDataSeeder): 1 Tahun Baru 2026-01-01 (jenis 1, status 1), 2 Cuti Bersama Idul Fitri 2026-03-19..20
 * (jenis 2, status 1), 3 Hari Buruh 2026-05-01 (status 2), 4 Hari Lahir Pancasila 2026-06-01 (status 10),
 * 5 Hari Raya Natal 2025-12-25 (tanpa jenis, status 1).
 *
 * @internal
 */
final class HariLiburTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    private const BASE = 'api/v1/hari-libur';

    private const ADMIN_NIP = '198501012010011001';

    /**
     * Koneksi kedua (sesi MySQL lain) untuk uji named lock.
     */
    private ?BaseConnection $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        Services::resetSingle('hariLiburService');
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        // Menutup sesi kedua melepas named lock-nya (RELEASE_ALL_LOCKS baru ada di MariaDB 10.5).
        if ($this->other !== null) {
            $this->other->close();
            $this->other = null;
        }

        Services::resetSingle('hariLiburService');
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // RBAC
    // ------------------------------------------------------------------

    public function testReadIsRole1458AndWriteIsRole1Only(): void
    {
        $readers = [Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];
        $this->assertSame($readers, HariLiburService::READ_ROLES);
        $this->assertSame([Role::SUPER_ADMIN], HariLiburService::WRITE_ROLES);

        foreach (Role::all() as $role) {
            $expected = in_array($role, $readers, true) ? 200 : 403;
            $this->asRole($role)->get(self::BASE)->assertStatus($expected);
            $this->asRole($role)->get(self::BASE . '/1')->assertStatus($expected);
        }

        $this->withHeaders(['Authorization' => ''])->get(self::BASE)->assertStatus(401);
        $this->withHeaders(['Authorization' => ''])->get(self::BASE . '/1')->assertStatus(401);

        $writes = [
            ['POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', 'Hari Kemerdekaan')],
            ['PUT', self::BASE . '/1', ['nama_libur' => 'Diubah']],
            ['PATCH', self::BASE . '/1/status', ['status' => '2']],
            ['DELETE', self::BASE . '/1', []],
        ];

        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            foreach ($writes as [$method, $uri, $body]) {
                $result = $this->asRole($role)->sendJson($method, $uri, $body);
                $result->assertStatus(403);
                $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "{$method} {$uri} role {$role}");
            }
        }

        foreach ($writes as [$method, $uri, $body]) {
            $this->withHeaders(['Authorization' => ''])->sendJson($method, $uri, $body)->assertStatus(401);
        }

        $this->assertSame(5, $this->db->table('hari_libur')->countAllResults());
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'nama_libur' => 'Tahun Baru 2026 Masehi', 'status' => 1]);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'hari_libur']);

        // Filter route berasal dari konstanta service (satu sumber).
        $routes = service('routes');
        $routes->loadRoutes();
        $this->assertContains(Role::filter(...HariLiburService::READ_ROLES), $routes->getFiltersForRoute(self::BASE, 'GET'));
        $this->assertContains(Role::filter(...HariLiburService::WRITE_ROLES), $routes->getFiltersForRoute(self::BASE, 'POST'));
    }

    // ------------------------------------------------------------------
    // Daftar & detail
    // ------------------------------------------------------------------

    public function testListVisibilityFiltersAndOrdering(): void
    {
        // Role 1: default tanpa status 10, urut tgl_mulai DESC; baris tanpa jenis tetap tampil (LEFT JOIN).
        $list = $this->listData();
        $this->assertSame(['3', '2', '1', '5'], $this->ids($list));
        $this->assertSame(4, $list['total']);
        $items = array_column($list['items'], null, 'id_libur');
        $this->assertSame('Libur Nasional', $items[1]['jenis_libur']);
        $this->assertArrayHasKey('updated_by', $items[1]);
        $this->assertNull($items[5]['jenis_libur']);
        $this->assertNull($items[5]['id_jenis_libur']);

        $this->assertSame(['4'], $this->ids($this->listData(['status' => '10'])));
        $this->assertSame(['3'], $this->ids($this->listData(['status' => '2'])));

        // Role 4/5/8: hanya status 1, ?status diabaikan; detail status 2/10 = 404.
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asRole($role);
            $this->assertSame(['2', '1', '5'], $this->ids($this->listData()), "role {$role}");
            $this->assertSame(['2', '1', '5'], $this->ids($this->listData(['status' => '10'])), "role {$role}");
            $this->get(self::BASE . '/3')->assertStatus(404);
            $this->get(self::BASE . '/4')->assertStatus(404);
            $detail = $this->get(self::BASE . '/2');
            $detail->assertStatus(200);
            $this->assertSame('Cuti Bersama Idul Fitri', $this->json($detail)['data']['nama_libur']);

            // Kolom audit internal (id_pengguna admin pengubah, waktu) tidak dikirim ke role baca saja.
            foreach ([...$this->listData()['items'], $this->json($detail)['data']] as $row) {
                $this->assertSame([], array_values(array_intersect(['created_at', 'updated_at', 'updated_by'], array_keys($row))), "role {$role}");
            }
        }

        $this->asRole(Role::SUPER_ADMIN);
        $admin = $this->get(self::BASE . '/4');
        $admin->assertStatus(200);
        $this->assertArrayHasKey('updated_by', $this->json($admin)['data']);
        $this->assertArrayHasKey('created_at', $this->json($admin)['data']);

        foreach (['0', '01', '1e0', 'abc', '99'] as $id) {
            $this->get(self::BASE . "/{$id}")->assertStatus(404);
        }

        // Tahun: rentang yang beririsan dengan tahun itu (lintas tahun muncul di kedua tahun).
        $this->sendJson('POST', self::BASE, $this->payload('2026-12-31', '2027-01-02', 'Libur Akhir Tahun'))->assertStatus(201);
        $this->assertSame(['6', '3', '2', '1'], $this->ids($this->listData(['tahun' => '2026'])));
        $this->assertSame(['6'], $this->ids($this->listData(['tahun' => '2027'])));
        $this->assertSame(['5'], $this->ids($this->listData(['tahun' => '2025'])));

        foreach (['abc', '26', '1899', '2101', '2026-01'] as $tahun) {
            $result = $this->get(self::BASE, ['tahun' => $tahun]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('tahun', $this->json($result)['errors'], $tahun);
        }

        // Cari nama (wildcard dicari sebagai karakter biasa) dan paginasi.
        $this->assertSame(['2'], $this->ids($this->listData(['search' => 'idul'])));
        $this->assertSame([], $this->ids($this->listData(['search' => '%'])));
        $page = $this->listData(['per_page' => '2', 'page' => '2']);
        $this->assertSame(['2', '1'], $this->ids($page));
        $this->assertSame([5, 2, 2], [$page['total'], $page['page'], $page['per_page']]);

        // per_page dijepit 1..100; page di luar 1..1.000.000 (termasuk nilai raksasa) → 422 seperti daftar master/akun
        // (ISSUE-019/CR-016), bukan halaman kosong; page valid di luar data tetap 200 kosong.
        $this->assertSame(100, $this->listData(['per_page' => '1000'])['per_page']);
        $this->assertSame(1, $this->listData(['per_page' => '0'])['per_page']);

        foreach ([Role::SUPER_ADMIN, Role::PIMPINAN] as $role) {
            $this->asRole($role);

            foreach (['0', '-1', '1e18', '1000001', '9223372036854775807', '99999999999999999999'] as $bad) {
                $result = $this->get(self::BASE, ['page' => $bad]);
                $result->assertStatus(422);
                $this->assertArrayHasKey('page', $this->json($result)['errors'], "page={$bad} role {$role}");
            }

            $far = $this->listData(['page' => '1000000', 'per_page' => '100']);
            $this->assertSame([], $far['items'], "role {$role}");
            $this->assertSame(1000000, $far['page'], "role {$role}");
        }

        $this->asRole(Role::SUPER_ADMIN);

        // Kata kunci > 100 karakter ditolak (100 masih boleh).
        $this->assertSame([], $this->listData(['search' => str_repeat('a', 100)])['items']);
        $result = $this->get(self::BASE, ['search' => str_repeat('a', 101)]);
        $result->assertStatus(422);
        $this->assertSame(['Kata kunci pencarian maksimal 100 karakter.'], $this->json($result)['errors']['search']);
    }

    // ------------------------------------------------------------------
    // Tambah & validasi
    // ------------------------------------------------------------------

    public function testCreateStoresNormalizedRowWithAudit(): void
    {
        Time::setTestNow('2026-02-03 04:05:06', 'UTC');

        $result = $this->sendJson('POST', self::BASE, [
            'tgl_mulai'  => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'id_jenis_libur' => '1',
            'nama_libur' => '  Hari   Kemerdekaan RI ', 'keterangan' => '   ',
        ]);
        $result->assertStatus(201);
        $row = $this->json($result)['data'];

        $this->assertSame(
            ['6', '1', 'Libur Nasional', '2026-08-17', '2026-08-17', 'Hari Kemerdekaan RI', null, '1', '2026-02-03 04:05:06'],
            [(string) $row['id_libur'], (string) $row['id_jenis_libur'], $row['jenis_libur'], $row['tgl_mulai'], $row['tgl_akhir'], $row['nama_libur'], $row['keterangan'], (string) $row['status'], $row['created_at']],
        );
        // Legacy sp_holiday: updated_by diisi saat tambah (tabel tanpa created_by).
        $this->assertSame($this->adminId(), (int) $row['updated_by']);
        $this->seeInDatabase('audit_logs', ['entity' => 'hari_libur', 'entity_id' => '6', 'event' => 'create', 'nip_actor' => self::ADMIN_NIP]);

        // Status 2 saat tambah (rencana libur, belum dihitung).
        $this->sendJson('POST', self::BASE, $this->payload('2026-09-01', '2026-09-01', 'Rencana Libur', ['status' => '2']))->assertStatus(201);
        $this->seeInDatabase('hari_libur', ['nama_libur' => 'Rencana Libur', 'status' => 2]);

        // Nama sama di tanggal lain diterima (tanpa UNIQUE nama, F4).
        $this->sendJson('POST', self::BASE, $this->payload('2027-01-01', '2027-01-01', 'Tahun Baru 2026 Masehi'))->assertStatus(201);

        // Ubah: updated_by & updated_at ikut di-stamp.
        Time::setTestNow('2026-02-04 05:06:07', 'UTC');
        $this->sendJson('PUT', self::BASE . '/6', ['keterangan' => 'Keppres'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_libur' => 6, 'keterangan' => 'Keppres', 'updated_at' => '2026-02-04 05:06:07', 'updated_by' => $this->adminId()]);
    }

    public function testDatesRangeJenisAndTextAreValidated(): void
    {
        $invalidDates = [
            '2026-02-30' => 'Tanggal mulai tidak valid.',
            '2026-1-1'   => 'Tanggal mulai harus tanggal dengan format YYYY-MM-DD.',
            '31-12-2026' => 'Tanggal mulai harus tanggal dengan format YYYY-MM-DD.',
            ''           => 'Tanggal mulai wajib diisi.',
            '1899-12-31' => 'Tanggal mulai harus tanggal yang valid dengan format YYYY-MM-DD (tahun 1900-2100).',
        ];

        foreach ($invalidDates as $date => $message) {
            $result = $this->sendJson('POST', self::BASE, $this->payload((string) $date, '2026-08-17', 'Tanggal Salah'));
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors']['tgl_mulai'] ?? null, var_export($date, true));
        }

        // Selesai sebelum mulai → 422 tgl_akhir; tanggal sama = libur satu hari.
        $result = $this->sendJson('POST', self::BASE, $this->payload('2026-08-18', '2026-08-17', 'Terbalik'));
        $result->assertStatus(422);
        $this->assertSame([HariLiburService::RANGE_MESSAGE], $this->json($result)['errors']['tgl_akhir']);
        $result = $this->sendJson('PUT', self::BASE . '/2', ['tgl_akhir' => '2026-03-18']);
        $result->assertStatus(422);
        $this->assertSame([HariLiburService::RANGE_MESSAGE], $this->json($result)['errors']['tgl_akhir']);

        // Jenis libur wajib (F5), kanonik, ada, dan aktif.
        $jenis = [
            [null, 'Jenis libur wajib dipilih.'],
            ['99', 'Jenis Libur tidak ditemukan.'],
            ['01', 'Jenis Libur tidak ditemukan.'],
            ['x', 'Jenis Libur tidak ditemukan.'],
        ];

        foreach ($jenis as [$value, $message]) {
            $payload = $this->payload('2026-08-17', '2026-08-17', 'Jenis Salah');

            if ($value === null) {
                unset($payload['id_jenis_libur']);
            } else {
                $payload['id_jenis_libur'] = $value;
            }

            $result = $this->sendJson('POST', self::BASE, $payload);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors']['id_jenis_libur'] ?? null, var_export($value, true));
        }

        $this->sendJson('PATCH', 'api/v1/master/jenis-libur/2/status', ['status' => '2'])->assertStatus(200);
        $result = $this->sendJson('POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', 'Jenis Nonaktif', ['id_jenis_libur' => '2']));
        $result->assertStatus(422);
        $this->assertSame(['Jenis Libur Cuti Bersama sedang non-aktif.'], $this->json($result)['errors']['id_jenis_libur']);
        // Data lama yang jenisnya kini non-aktif tetap bisa diubah kolom lainnya (jenis tidak berubah).
        $this->sendJson('PUT', self::BASE . '/2', ['nama_libur' => 'Cuti Bersama Idul Fitri 1447 H', 'id_jenis_libur' => '2'])->assertStatus(200);

        // Nama ≤ 100 karakter, wajib; keterangan ≤ 65.535 byte.
        $result = $this->sendJson('POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', str_repeat('a', 101)));
        $result->assertStatus(422);
        $this->assertArrayHasKey('nama_libur', $this->json($result)['errors']);
        $this->sendJson('POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', '   '))->assertStatus(422);
        $result = $this->sendJson('POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', 'Keterangan Panjang', ['keterangan' => str_repeat('é', 32768)]));
        $result->assertStatus(422);
        $this->assertSame(['Keterangan maksimal 65.535 byte.'], $this->json($result)['errors']['keterangan']);

        $this->assertSame(5, $this->db->table('hari_libur')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Overlap (Paket A)
    // ------------------------------------------------------------------

    public function testOverlapIsCheckedAgainstEveryStatus(): void
    {
        $cases = [
            [['2026-03-20', '2026-03-21'], 'Rentang tanggal bentrok dengan hari libur "Cuti Bersama Idul Fitri" (2026-03-19 s.d. 2026-03-20).'],
            [['2026-04-30', '2026-05-02'], 'Rentang tanggal bentrok dengan hari libur "Hari Buruh Internasional" (2026-05-01 s.d. 2026-05-01) (tidak aktif — aktifkan atau ubah entri tersebut).'],
            [['2026-06-01', '2026-06-01'], 'Rentang tanggal bentrok dengan hari libur "Hari Lahir Pancasila" (2026-06-01 s.d. 2026-06-01) (sudah dihapus — pulihkan atau ubah entri tersebut lewat filter status Dihapus).'],
        ];

        foreach ($cases as [[$mulai, $akhir], $message]) {
            $result = $this->sendJson('POST', self::BASE, $this->payload($mulai, $akhir, 'Bentrok'));
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors']['tgl_mulai'], "{$mulai}..{$akhir}");
        }

        $this->dontSeeInDatabase('hari_libur', ['nama_libur' => 'Bentrok']);

        // Bersebelahan (sebelum & sesudah) tidak bentrok.
        $this->sendJson('POST', self::BASE, $this->payload('2026-03-21', '2026-03-22', 'Sesudah Cuti'))->assertStatus(201);
        $this->sendJson('POST', self::BASE, $this->payload('2026-03-18', '2026-03-18', 'Sebelum Cuti'))->assertStatus(201);

        // Ubah tanpa ganti tanggal / memperpanjang diri sendiri tidak bentrok dengan dirinya.
        $this->sendJson('PUT', self::BASE . '/1', ['nama_libur' => 'Tahun Baru Masehi 2026', 'tgl_mulai' => '2026-01-01'])->assertStatus(200);
        $this->sendJson('PUT', self::BASE . '/1', ['tgl_akhir' => '2026-01-02'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'tgl_akhir' => '2026-01-02']);

        // Menggeser ke rentang entri lain ditolak, termasuk entri yang dihapus.
        $result = $this->sendJson('PUT', self::BASE . '/1', ['tgl_mulai' => '2026-05-31', 'tgl_akhir' => '2026-06-02']);
        $result->assertStatus(422);
        $this->assertStringContainsString('Hari Lahir Pancasila', $this->json($result)['errors']['tgl_mulai'][0]);
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'tgl_mulai' => '2026-01-01']);

        // Data lama (impor legacy) yang sudah beririsan tetap bisa diubah kolom non-tanggalnya: overlap hanya dicek
        // bila tanggal berubah (mengirim tanggal yang sama = tidak berubah).
        $this->db->table('hari_libur')->insert(['id_jenis_libur' => 2, 'tgl_mulai' => '2026-04-30', 'tgl_akhir' => '2026-05-01', 'nama_libur' => 'Data Lama Beririsan', 'status' => 1]);
        $legacyId = (string) $this->db->insertID();
        $this->sendJson('PUT', self::BASE . "/{$legacyId}", ['nama_libur' => 'Data Lama Diubah', 'keterangan' => 'Impor', 'id_jenis_libur' => '1'])->assertStatus(200);
        $this->sendJson('PUT', self::BASE . "/{$legacyId}", ['tgl_mulai' => '2026-04-30', 'tgl_akhir' => '2026-05-01'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_libur' => $legacyId, 'nama_libur' => 'Data Lama Diubah', 'keterangan' => 'Impor', 'tgl_akhir' => '2026-05-01']);

        $result = $this->sendJson('PUT', self::BASE . "/{$legacyId}", ['tgl_mulai' => '2026-04-29']);
        $result->assertStatus(422);
        $this->assertStringContainsString('Hari Buruh Internasional', $this->json($result)['errors']['tgl_mulai'][0]);
        $this->seeInDatabase('hari_libur', ['id_libur' => $legacyId, 'tgl_mulai' => '2026-04-30']);
    }

    public function testDeleteIsSoftAndEntryCanBeRestored(): void
    {
        $result = $this->delete(self::BASE . '/1');
        $result->assertStatus(200);
        $body = $this->json($result)['data'];
        $this->assertSame([true, true, '10'], [$body['deleted'], $body['soft_delete'], (string) $body['item']['status']]);
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'status' => 10]);
        $this->seeInDatabase('audit_logs', ['entity' => 'hari_libur', 'entity_id' => '1', 'event' => 'delete', 'nip_actor' => self::ADMIN_NIP]);
        $this->assertNotContains('1', $this->ids($this->listData()));
        $this->assertSame(['4', '1'], $this->ids($this->listData(['status' => '10'])));

        // Tanggalnya tetap terpakai (Paket A): harus dipulihkan, bukan dibuat ganda.
        $this->sendJson('POST', self::BASE, $this->payload('2026-01-01', '2026-01-01', 'Tahun Baru Ganda'))->assertStatus(422);

        $this->sendJson('PATCH', self::BASE . '/1/status', ['status' => '1'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'status' => 1]);
        $this->sendJson('PATCH', self::BASE . '/4/status', ['status' => '10'])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/4', ['status' => '2'])->assertStatus(200);
        $this->seeInDatabase('hari_libur', ['id_libur' => 4, 'status' => 2]);
        $this->sendJson('PUT', self::BASE . '/4', ['status' => ''])->assertStatus(422);
        $this->delete(self::BASE . '/99')->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Lapis DB (balapan, CHECK) & lock
    // ------------------------------------------------------------------

    /**
     * Balapan: cek overlap aplikasi dilewati (subclass), UNIQUE tgl_mulai menolak (1062) → 422 pada tgl_mulai,
     * transaksi di-rollback bersih dan lock dilepas.
     */
    public function testUniqueTglMulaiRaceIsTranslatedTo422(): void
    {
        $service = new class () extends HariLiburService {
            protected function findOverlap(string $mulai, string $akhir, ?int $exceptId): ?array
            {
                return null;
            }
        };

        try {
            $service->create($this->payload('2026-01-01', '2026-01-03', 'Pemenang Balapan'));
            $this->fail('Pelanggaran UNIQUE tgl_mulai harus menjadi ValidationException (422).');
        } catch (ValidationException $e) {
            $this->assertSame(['tgl_mulai' => ['Tanggal mulai sudah dipakai hari libur lain.']], $e->getErrors());
        }

        $this->assertSame(5, $this->db->table('hari_libur')->countAllResults());
        $this->assertTrue($this->db->transStatus());
        $this->assertSame('1', (string) $this->db->query('SELECT IS_FREE_LOCK(?) AS f', [$service->lockName()])->getRowArray()['f']);

        // Pemenang balapan terlihat saat 1062 diterjemahkan: cek pertama "kalah" (null), cek ulang menemukan entri yang
        // bentrok → pesan overlap yang menyebut entri itu, bukan pesan generik.
        $racing = new class () extends HariLiburService {
            private int $calls = 0;

            protected function findOverlap(string $mulai, string $akhir, ?int $exceptId): ?array
            {
                return $this->calls++ === 0 ? null : parent::findOverlap($mulai, $akhir, $exceptId);
            }
        };

        try {
            $racing->create($this->payload('2026-01-01', '2026-01-03', 'Pemenang Balapan'));
            $this->fail('Pelanggaran UNIQUE tgl_mulai harus menjadi ValidationException (422).');
        } catch (ValidationException $e) {
            $this->assertSame(['tgl_mulai' => ['Rentang tanggal bentrok dengan hari libur "Tahun Baru 2026 Masehi" (2026-01-01 s.d. 2026-01-01).']], $e->getErrors());
        }

        $this->assertSame(5, $this->db->table('hari_libur')->countAllResults());
    }

    /**
     * CHECK chk_hari_libur_rentang (3819 MySQL / 4025 MariaDB) bila cek aplikasi terlewati → 422 pada tgl_akhir.
     */
    public function testCheckViolationIsTranslatedTo422(): void
    {
        $service = new class () extends HariLiburService {
            protected function assertValidRange(string $mulai, string $akhir): void
            {
            }
        };

        foreach ([static fn () => $service->create(['tgl_mulai' => '2026-08-18', 'tgl_akhir' => '2026-08-17', 'id_jenis_libur' => '1', 'nama_libur' => 'Terbalik']), static fn () => $service->update('1', ['tgl_akhir' => '2025-12-31'])] as $write) {
            try {
                $write();
                $this->fail('Pelanggaran CHECK harus menjadi ValidationException (422).');
            } catch (ValidationException $e) {
                $this->assertSame(['tgl_akhir' => [HariLiburService::RANGE_MESSAGE]], $e->getErrors());
            }
        }

        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'tgl_akhir' => '2026-01-01']);
        $this->dontSeeInDatabase('hari_libur', ['nama_libur' => 'Terbalik']);
    }

    /**
     * Penulisan diserialkan named lock: selama sesi lain memegang lock, tulis menunggu lalu 409 tanpa perubahan;
     * setelah dilepas, tulis berhasil.
     */
    public function testWriteWaitsForNamedLockAndReturns409OnTimeout(): void
    {
        $service = new HariLiburService(null, 0);
        Services::injectMock('hariLiburService', $service);

        $this->other = db_connect('tests', false);
        $this->assertSame('1', (string) $this->other->query('SELECT GET_LOCK(?, 1) AS l', [$service->lockName()])->getRowArray()['l']);

        foreach ([
            ['POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', 'Menunggu Lock')],
            ['PUT', self::BASE . '/1', ['nama_libur' => 'Menunggu Lock']],
            ['PATCH', self::BASE . '/1/status', ['status' => '2']],
            ['DELETE', self::BASE . '/1', []],
        ] as [$method, $uri, $body]) {
            $result = $this->sendJson($method, $uri, $body);
            $result->assertStatus(409);
            $this->assertSame(HariLiburService::LOCK_BUSY_MESSAGE, $this->json($result)['message'], "{$method} {$uri}");
        }

        $this->dontSeeInDatabase('hari_libur', ['nama_libur' => 'Menunggu Lock']);
        $this->seeInDatabase('hari_libur', ['id_libur' => 1, 'status' => 1]);

        $this->other->query('SELECT RELEASE_LOCK(?)', [$service->lockName()]);
        $this->sendJson('POST', self::BASE, $this->payload('2026-08-17', '2026-08-17', 'Setelah Lock'))->assertStatus(201);
        $this->assertSame('1', (string) $this->db->query('SELECT IS_FREE_LOCK(?) AS f', [$service->lockName()])->getRowArray()['f']);
    }

    // ------------------------------------------------------------------
    // tanggalLibur() — sumber tunggal Fase 5
    // ------------------------------------------------------------------

    public function testTanggalLiburReturnsOnlyStatusOneDatesClippedUniqueSorted(): void
    {
        $service = service('hariLiburService');

        // Status 2 (1 Mei) dan 10 (1 Juni) tidak dihitung; baris tanpa jenis (Natal 2025) dihitung.
        $this->assertSame(['2026-01-01', '2026-03-19', '2026-03-20'], $service->tanggalLibur('2026-01-01', '2026-12-31'));
        $this->assertSame(['2025-12-25'], $service->tanggalLibur(new DateTimeImmutable('2025-12-01'), '2025-12-31'));

        // Dipotong ke rentang, unik & terurut walau data lama saling beririsan.
        $this->db->table('hari_libur')->insert(['id_jenis_libur' => 2, 'tgl_mulai' => '2026-03-20', 'tgl_akhir' => '2026-03-23', 'nama_libur' => 'Data Lama Beririsan', 'status' => 1]);
        $this->assertSame(['2026-03-20', '2026-03-21'], $service->tanggalLibur('2026-03-20', '2026-03-21'));
        $this->assertSame(['2026-03-19', '2026-03-20', '2026-03-21', '2026-03-22', '2026-03-23'], $service->tanggalLibur('2026-03-01', '2026-03-31'));

        // Status jenis libur tidak berpengaruh; lintas tahun dihitung per hari.
        $this->db->table('jenis_libur')->where('id_jenis_libur', 2)->update(['status' => 2]);
        $this->sendJson('POST', self::BASE, $this->payload('2026-12-31', '2027-01-02', 'Libur Akhir Tahun'))->assertStatus(201);
        $this->assertSame(['2026-03-19', '2026-03-20'], $service->tanggalLibur('2026-03-19', '2026-03-20'));
        $this->assertSame(['2026-12-31', '2027-01-01', '2027-01-02'], $service->tanggalLibur('2026-12-30', '2027-01-05'));
        $this->assertSame([], $service->tanggalLibur('2026-07-01', '2026-07-31'));

        foreach ([['2026-02-01', '2026-01-01'], ['2026-02-30', '2026-03-01'], ['2026-1-1', '2026-01-31']] as [$from, $to]) {
            try {
                $service->tanggalLibur($from, $to);
                $this->fail("tanggalLibur({$from}, {$to}) harus ditolak.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function payload(string $mulai, string $akhir, string $nama, array $override = []): array
    {
        return $override + ['tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'id_jenis_libur' => '1', 'nama_libur' => $nama];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    private function listData(array $query = []): array
    {
        $result = $this->get(self::BASE, $query);
        $result->assertStatus(200);

        return $this->json($result)['data'];
    }

    /**
     * @param array{items: list<array<string, mixed>>} $list
     *
     * @return list<string>
     */
    private function ids(array $list): array
    {
        return array_map(static fn (array $row): string => (string) $row['id_libur'], $list['items']);
    }

    private function adminId(): int
    {
        return (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
    }
}
