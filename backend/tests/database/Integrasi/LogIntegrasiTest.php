<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use App\Libraries\Integrasi\Log\DaftarLogIntegrasi;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-078 (ISSUE-058, deviasi K1 Fase 6) — `GET api/v1/integrasi/log` (PRD Fase 6 §6.2, F6-24): role 1 saja, filter
 * `layanan`/`status`/`tanggal_mulai`/`tanggal_selesai` (tanggal WIB atas `created_at` UTC), paginasi, urut terbaru dulu,
 * 422 per parameter, dan hanya kolom DBV-027 `integrasi_log` yang keluar. Data sintetis.
 *
 * @internal
 */
final class LogIntegrasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;

    private const URL = 'api/v1/integrasi/log';

    protected $seed = AuthSeeder::class;

    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->buatAkunTanpaNip('admin.uji.log', Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRole1MenerimaDaftarTerbaruDuluDenganKolomAmanSaja(): void
    {
        $lama   = $this->log('bkn', 1, '2026-11-01 01:00:00', ['operasi' => 'token', 'http_status' => 200, 'durasi_ms' => 120]);
        $baru   = $this->log('bsre', 2, '2026-11-02 03:00:00', ['operasi' => 'sign_pdf', 'http_status' => 500, 'kode' => 'E500', 'pesan' => 'Gagal (teredaksi)', 'percobaan' => 3, 'ref_id' => 'cuti:12']);
        $kembar = $this->log('fcm', 1, '2026-11-02 03:00:00', ['arah' => 1]);

        $data = $this->dataOk($this->get200());

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(DaftarLogIntegrasi::PER_PAGE_DEFAULT, $data['per_page']);
        $this->assertSame([$kembar, $baru, $lama], array_column($data['items'], 'id_integrasi_log'), 'created_at DESC lalu id DESC');
        $this->assertSame(['bkn', 'bsre', 'fcm'], $data['layanan_tersedia']);

        foreach ($data['items'] as $item) {
            $this->assertSame(DaftarLogIntegrasi::KOLOM, array_keys($item), 'hanya kolom integrasi_log, tanpa join data pegawai/akun');
        }

        $this->assertSame([
            'id_integrasi_log' => $baru,
            'arah'             => 1,
            'layanan'          => 'bsre',
            'operasi'          => 'sign_pdf',
            'status'           => 2,
            'http_status'      => 500,
            'kode'             => 'E500',
            'pesan'            => 'Gagal (teredaksi)',
            'durasi_ms'        => null,
            'percobaan'        => 3,
            'ref_id'           => 'cuti:12',
            'id_klien'         => null,
            'created_at'       => '2026-11-02 03:00:00',
        ], $data['items'][1]);

        $json = json_encode($data, JSON_THROW_ON_ERROR);

        foreach (['nip', 'username', 'password', 'token_hash', 'ip_address', 'user_agent'] as $kunci) {
            $this->assertStringNotContainsString('"' . $kunci . '"', $json);
        }
    }

    public function testDaftarKosong(): void
    {
        $data = $this->dataOk($this->get200());

        $this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'layanan_tersedia' => []], $data);
    }

    public function testRoleLainDitolak403DanTanpaTokenDitolak401(): void
    {
        $this->log('bkn', 1, '2026-11-01 01:00:00');

        foreach ([Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asUser($this->buatAkunTanpaNip('akun.uji.log.' . $role, $role))->get(self::URL)->assertStatus(403);
        }

        // Role pegawai (2/6/7) lewat akun seed AuthSeeder per role.
        foreach (Role::UL_PEGAWAI as $role) {
            $this->asRole($role)->get(self::URL)->assertStatus(403);
        }

        // Tanpa token: header Authorization permintaan sebelumnya dibuang dulu (FeatureTestTrait menyimpannya).
        $this->headers = [];
        $this->clearAuthState();
        $this->get(self::URL)->assertStatus(401);
    }

    public function testFilterLayananDanStatus(): void
    {
        $a = $this->log('bkn', 1, '2026-11-01 01:00:00');
        $b = $this->log('bkn', 2, '2026-11-01 02:00:00');
        $c = $this->log('siasn', 2, '2026-11-01 03:00:00');

        $this->assertSame([$b, $a], $this->ids(['layanan' => 'bkn']));
        $this->assertSame([$c, $b], $this->ids(['status' => '2']));
        $this->assertSame([$b], $this->ids(['layanan' => 'bkn', 'status' => '2']));
        $this->assertSame([], $this->ids(['layanan' => 'fcm']));
        $this->assertSame([$c, $b, $a], $this->ids(['layanan' => '', 'status' => '  ']), 'parameter kosong diabaikan');

        $data = $this->dataOk($this->get200(['layanan' => 'bkn']));
        $this->assertSame(['bkn', 'siasn'], $data['layanan_tersedia'], 'pilihan filter tidak ikut tersaring');
    }

    public function testFilterTanggalWibInklusifAtasCreatedAtUtc(): void
    {
        // 2026-11-09 WIB = [2026-11-08 17:00:00, 2026-11-09 17:00:00) UTC.
        $sebelum = $this->log('bkn', 1, '2026-11-08 16:59:59'); // 08-11 23:59:59 WIB
        $awal    = $this->log('bkn', 1, '2026-11-08 17:00:00'); // 09-11 00:00:00 WIB
        $akhir   = $this->log('bkn', 1, '2026-11-09 16:59:59'); // 09-11 23:59:59 WIB
        $sesudah = $this->log('bkn', 1, '2026-11-09 17:00:00'); // 10-11 00:00:00 WIB

        $this->assertSame([$akhir, $awal], $this->ids(['tanggal_mulai' => '2026-11-09', 'tanggal_selesai' => '2026-11-09']));
        $this->assertSame([$sesudah, $akhir, $awal], $this->ids(['tanggal_mulai' => '2026-11-09']));
        $this->assertSame([$akhir, $awal, $sebelum], $this->ids(['tanggal_selesai' => '2026-11-09']));
        $this->assertSame([$sesudah, $akhir, $awal, $sebelum], $this->ids(['tanggal_mulai' => '2026-11-08', 'tanggal_selesai' => '2026-11-10']));
    }

    public function testPaginasi(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->log('bkn', 1, sprintf('2026-11-01 0%d:00:00', $i));
        }

        $terbaru = array_reverse($ids);

        $h1 = $this->dataOk($this->get200(['per_page' => '2']));
        $h3 = $this->dataOk($this->get200(['per_page' => '2', 'page' => '3']));
        $h9 = $this->dataOk($this->get200(['per_page' => '2', 'page' => '9']));

        $this->assertSame([5, 1, 2], [$h1['total'], $h1['page'], $h1['per_page']]);
        $this->assertSame(array_slice($terbaru, 0, 2), array_column($h1['items'], 'id_integrasi_log'));
        $this->assertSame([$terbaru[4]], array_column($h3['items'], 'id_integrasi_log'));
        $this->assertSame([], $h9['items'], 'halaman lewat jumlah data → kosong, bukan galat');
        $this->assertSame(DaftarLogIntegrasi::PER_PAGE_MAX, $this->dataOk($this->get200(['per_page' => '5000']))['per_page'], 'per_page dijepit');
    }

    public function testFilterSalah422PerParameterSekaligus(): void
    {
        $errors = $this->errors422([
            'layanan'         => 'BKN; DROP',
            'status'          => '3',
            'tanggal_mulai'   => '2026-02-30',
            'tanggal_selesai' => '09-11-2026',
        ]);

        $this->assertSame([DaftarLogIntegrasi::PESAN_LAYANAN], $errors['layanan']);
        $this->assertSame([DaftarLogIntegrasi::PESAN_STATUS], $errors['status']);
        $this->assertSame([DaftarLogIntegrasi::PESAN_TANGGAL], $errors['tanggal_mulai']);
        $this->assertSame([DaftarLogIntegrasi::PESAN_TANGGAL], $errors['tanggal_selesai']);

        $this->assertSame(['tanggal_selesai' => [DaftarLogIntegrasi::PESAN_RENTANG]], $this->errors422(['tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-09']));
        $this->assertArrayHasKey('page', $this->errors422(['page' => '0']));
        $this->assertArrayHasKey('layanan', $this->errors422(['layanan' => str_repeat('a', 31)]));
        $this->assertArrayHasKey('status', $this->errors422(['status' => '1.0']));
    }

    public function testParameterBerbentukArray422(): void
    {
        $res = $this->asUser($this->admin)->get(self::URL . '?layanan[]=bkn&status[]=1');
        $res->assertStatus(422);

        $errors = json_decode($res->getJSON(), true)['errors'];
        $this->assertArrayHasKey('layanan', $errors);
        $this->assertArrayHasKey('status', $errors);
    }

    /**
     * @param array<string, mixed> $isi
     */
    private function log(string $layanan, int $status, string $createdAtUtc, array $isi = []): int
    {
        $this->db->table('integrasi_log')->insert(array_merge([
            'arah'       => 1,
            'layanan'    => $layanan,
            'operasi'    => 'uji',
            'status'     => $status,
            'percobaan'  => 1,
            'created_at' => $createdAtUtc,
        ], $isi));

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, string> $query
     */
    private function get200(array $query = []): TestResponse
    {
        $res = $this->asUser($this->admin)->get(self::URL . ($query === [] ? '' : '?' . http_build_query($query)));
        $res->assertStatus(200);

        return $res;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOk(TestResponse $res): array
    {
        $body = json_decode($res->getJSON(), true);
        $this->assertSame('success', $body['status']);

        return $body['data'];
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<int>
     */
    private function ids(array $query): array
    {
        return array_column($this->dataOk($this->get200($query))['items'], 'id_integrasi_log');
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, list<string>>
     */
    private function errors422(array $query): array
    {
        $res = $this->asUser($this->admin)->get(self::URL . '?' . http_build_query($query));
        $res->assertStatus(422);

        return json_decode($res->getJSON(), true)['errors'];
    }
}
