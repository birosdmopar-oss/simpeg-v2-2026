<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Laporan\PenyesuaianTukinService;
use CodeIgniter\Test\FeatureTestTrait;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * PRD 5B §6.1, §7.3.10 AC-D08.11 (bagian CRUD, MAKE-045): penyesuaian Tukin per NIP — validasi 422 per field (NIP ada,
 * bulan `YYYY-MM` ≥ 2024-04 & mulai ≤ akhir, persen 2 desimal ≠ 0 dalam ±100, alasan ≤ 255, status 1/2), tumpang tindih
 * hanya antar-baris status 1 milik NIP yang sama, NIP tidak bisa diubah, hapus = status 10 + audit `delete`, audit
 * `create`/`update`, daftar & filter, 404, role route 1 saja (2..8 → 403, tanpa token 401). Konkurensi C-1 di
 * `PenyesuaianTukinKonkurensiTest`.
 *
 * @internal
 */
final class PenyesuaianTukinCrudTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const ID_ADMIN = 9101;

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testTambahUbahHapusDenganAudit(): void
    {
        $nip  = $this->buatPegawai();
        $baru = $this->service()->tambah($this->admin(), $this->isi($nip, ['persen' => '-20', 'alasan' => '  Predikat Kinerja Sangat Kurang ']));

        $this->assertSame([$nip, '2024-04', '2024-06', '-20.00', 'Predikat Kinerja Sangat Kurang', 1], [$baru['nip'], $baru['bulan_mulai'], $baru['bulan_akhir'], $baru['persen'], $baru['alasan'], $baru['status']]);
        $this->assertSame('2026-10-28 02:00:00', $baru['created_at'], 'UTC dari jam disuntik');
        $this->seeInDatabase('penyesuaian_tukin', ['id_penyesuaian_tukin' => $baru['id'], 'bulan_mulai' => '2024-04-01', 'created_by' => self::ID_ADMIN]);
        $this->seeInDatabase('audit_logs', ['entity' => 'penyesuaian_tukin', 'entity_id' => (string) $baru['id'], 'event' => 'create']);

        $ubah = $this->service()->ubah($this->admin(), $baru['id'], $this->isi($nip, ['bulan_akhir' => '2024-05', 'persen' => -10.5, 'status' => 2]));
        $this->assertSame(['2024-05', '-10.50', 2], [$ubah['bulan_akhir'], $ubah['persen'], $ubah['status']]);
        $this->seeInDatabase('audit_logs', ['entity' => 'penyesuaian_tukin', 'entity_id' => (string) $baru['id'], 'event' => 'update']);

        $this->assertSame(['deleted' => true, 'id' => $baru['id']], $this->service()->hapus($this->admin(), $baru['id']));
        $this->seeInDatabase('penyesuaian_tukin', ['id_penyesuaian_tukin' => $baru['id'], 'status' => 10, 'updated_by' => self::ID_ADMIN]);
        $this->seeInDatabase('audit_logs', ['entity' => 'penyesuaian_tukin', 'entity_id' => (string) $baru['id'], 'event' => 'delete']);

        foreach ([fn () => $this->service()->ambil($baru['id']), fn () => $this->service()->hapus($this->admin(), $baru['id']), fn () => $this->service()->ubah($this->admin(), $baru['id'], $this->isi($nip))] as $aksi) {
            try {
                $aksi();
                $this->fail('baris status 10 harus 404');
            } catch (ApiException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function isianTidakSah(): iterable
    {
        yield 'nip kosong' => [['nip' => ''], 'nip'];
        yield 'nip tidak ada' => [['nip' => '199912312099121099'], 'nip'];
        yield 'bulan format' => [['bulan_mulai' => '2024-4'], 'bulan_mulai'];
        yield 'bulan 13' => [['bulan_akhir' => '2024-13'], 'bulan_akhir'];
        yield 'bulan sebelum 2024-04' => [['bulan_mulai' => '2024-03'], 'bulan_mulai'];
        yield 'akhir sebelum mulai' => [['bulan_mulai' => '2024-06', 'bulan_akhir' => '2024-05'], 'bulan_akhir'];
        yield 'persen nol' => [['persen' => '0.00'], 'persen'];
        yield 'persen tiga desimal' => [['persen' => '-20.125'], 'persen'];
        yield 'persen di bawah -100' => [['persen' => '-100.01'], 'persen'];
        yield 'persen di atas 100' => [['persen' => 100.01], 'persen'];
        yield 'persen teks' => [['persen' => 'dua puluh'], 'persen'];
        yield 'alasan kosong' => [['alasan' => '   '], 'alasan'];
        yield 'alasan 256' => [['alasan' => str_repeat('a', 256)], 'alasan'];
        yield 'status 10' => [['status' => 10], 'status'];
    }

    /**
     * @param array<string, mixed> $timpa
     */
    #[DataProvider('isianTidakSah')]
    public function testValidasiPerField(array $timpa, string $field): void
    {
        try {
            $this->service()->tambah($this->admin(), $this->isi($this->buatPegawai(), $timpa));
            $this->fail('harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->getErrors());
        }

        $this->assertSame(0, $this->db->table('penyesuaian_tukin')->countAllResults());
    }

    public function testNilaiBatasSah(): void
    {
        $nip = $this->buatPegawai();

        $this->assertSame('-100.00', $this->service()->tambah($this->admin(), $this->isi($nip, ['bulan_mulai' => '2024-04', 'bulan_akhir' => '2024-04', 'persen' => -100]))['persen']);
        $this->assertSame('100.00', $this->service()->tambah($this->admin(), $this->isi($nip, ['bulan_mulai' => '2024-05', 'bulan_akhir' => '2024-05', 'persen' => '100']))['persen']);
        $this->assertSame('-0.01', $this->service()->tambah($this->admin(), $this->isi($nip, ['bulan_mulai' => '2024-06', 'bulan_akhir' => '2024-06', 'persen' => '-0.01']))['persen']);
        $this->assertSame('255', (string) mb_strlen($this->service()->tambah($this->admin(), $this->isi($nip, ['bulan_mulai' => '2024-07', 'bulan_akhir' => '2024-07', 'alasan' => str_repeat('é', 255)]))['alasan']));
    }

    public function testTumpangTindihHanyaAntarBarisAktifNipSama(): void
    {
        $a = $this->buatPegawai();
        $b = $this->buatPegawai();
        $s = $this->service();

        $pertama = $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-04', 'bulan_akhir' => '2024-06']));

        try {
            $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-06', 'bulan_akhir' => '2024-08']));
            $this->fail('irisan 2024-06 harus 422');
        } catch (ValidationException $e) {
            $this->assertStringStartsWith('Rentang bulan beririsan', $e->getErrors()['bulan_mulai'][0] ?? '');
            $this->assertStringContainsString('2024-04 s.d. 2024-06', $e->getErrors()['bulan_mulai'][0] ?? '');
        }

        $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-07', 'bulan_akhir' => '2024-08']));
        $s->tambah($this->admin(), $this->isi($b, ['bulan_mulai' => '2024-05', 'bulan_akhir' => '2024-05']));
        $nonaktif = $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-05', 'bulan_akhir' => '2024-05', 'status' => 2]));

        // Mengaktifkan baris yang beririsan ditolak; mengubah baris sendiri tidak bentrok dengan dirinya.
        try {
            $s->ubah($this->admin(), $nonaktif['id'], $this->isi($a, ['bulan_mulai' => '2024-05', 'bulan_akhir' => '2024-05', 'status' => 1]));
            $this->fail('aktifkan baris beririsan harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bulan_mulai', $e->getErrors());
        }

        $this->assertSame('2024-05', $s->ubah($this->admin(), $pertama['id'], $this->isi($a, ['bulan_mulai' => '2024-04', 'bulan_akhir' => '2024-05']))['bulan_akhir']);

        // Baris yang dihapus (status 10) tidak lagi menghalangi.
        $s->hapus($this->admin(), $pertama['id']);
        $this->assertSame(1, $s->ubah($this->admin(), $nonaktif['id'], $this->isi($a, ['bulan_mulai' => '2024-05', 'bulan_akhir' => '2024-05', 'status' => 1]))['status']);
    }

    public function testNipTidakBisaDiubah(): void
    {
        $nip  = $this->buatPegawai();
        $lain = $this->buatPegawai();
        $id   = $this->service()->tambah($this->admin(), $this->isi($nip))['id'];

        try {
            $this->service()->ubah($this->admin(), $id, $this->isi($lain));
            $this->fail('ganti NIP harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nip', $e->getErrors());
        }

        $this->assertSame($nip, $this->service()->ubah($this->admin(), $id, $this->isi($nip, ['persen' => '-5']))['nip'], 'NIP sama boleh dikirim');
    }

    public function testDaftarDanFilter(): void
    {
        $a = $this->buatPegawai(['nama' => 'Andi Uji']);
        $b = $this->buatPegawai();
        $s = $this->service();

        $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-04', 'bulan_akhir' => '2024-06']));
        $s->tambah($this->admin(), $this->isi($a, ['bulan_mulai' => '2024-07', 'bulan_akhir' => '2024-07', 'status' => 2]));
        $hapus = $s->tambah($this->admin(), $this->isi($b, ['bulan_mulai' => '2025-01', 'bulan_akhir' => '2025-02']));
        $s->hapus($this->admin(), $hapus['id']);

        $semua = $s->daftar($this->admin(), []);
        $this->assertSame(2, $semua['total'], 'bawaan status 1 dan 2');
        $this->assertSame(['2024-07', '2024-04'], array_column($semua['items'], 'bulan_mulai'), 'urut NIP lalu bulan terbaru');
        $this->assertSame('Andi Uji', $semua['items'][0]['nama']);

        $this->assertSame(1, $s->daftar($this->admin(), ['bulan' => '2024-05'])['total']);
        $this->assertSame(0, $s->daftar($this->admin(), ['bulan' => '2024-03'])['total'], 'filter bulan boleh sebelum 2024-04');
        $this->assertSame(1, $s->daftar($this->admin(), ['status' => '10'])['total']);
        $this->assertSame(0, $s->daftar($this->admin(), ['nip' => $b])['total']);
        $this->assertSame(1, $s->daftar($this->admin(), ['nip' => $b, 'status' => '10'])['total']);

        foreach ([['bulan' => '2024/05'], ['status' => '3']] as $query) {
            try {
                $s->daftar($this->admin(), $query);
                $this->fail('filter tidak sah harus 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey((string) array_key_first($query), $e->getErrors());
            }
        }
    }

    public function testServiceMenolakSelainRole1(): void
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '9102', 'role' => Role::ADMIN_SATKER]);

        try {
            $this->service()->daftar($auth, []);
            $this->fail('role 3 harus 403');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testRouteHanyaRole1DanAlurHttp(): void
    {
        $nip = $this->buatPegawai();

        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('pt' . $role . bin2hex(random_bytes(3)), $role, ['nip' => in_array($role, Role::UL_PEGAWAI, true) ? $this->buatPegawai() : null, 'id_satker' => 'S01', 'must_change_password' => 0]);

            foreach ([['get', 'api/v1/presensi/tukin/penyesuaian'], ['post', 'api/v1/presensi/tukin/penyesuaian'], ['put', 'api/v1/presensi/tukin/penyesuaian/1'], ['delete', 'api/v1/presensi/tukin/penyesuaian/1']] as [$verb, $url]) {
                $this->assertSame(403, $this->asUser($akun)->withBodyFormat('json')->{$verb}($url)->response()->getStatusCode(), "role {$role} {$verb} {$url}");
            }
        }

        $admin = $this->buatAkunTanpaNip('ptadm' . bin2hex(random_bytes(3)), Role::SUPER_ADMIN, ['must_change_password' => 0]);
        $buat  = $this->asUser($admin)->withBodyFormat('json')->post('api/v1/presensi/tukin/penyesuaian', $this->isi($nip));
        $buat->assertStatus(201);
        $id = (int) (json_decode($buat->getJSON(), true)['data']['id'] ?? 0);

        $this->asUser($admin)->withBodyFormat('json')->put("api/v1/presensi/tukin/penyesuaian/{$id}", $this->isi($nip, ['persen' => '-10']))->assertStatus(200);
        $this->asUser($admin)->withBodyFormat('json')->post('api/v1/presensi/tukin/penyesuaian', $this->isi($nip))->assertStatus(422);
        $daftar = $this->asUser($admin)->get('api/v1/presensi/tukin/penyesuaian?nip=' . $nip);
        $daftar->assertStatus(200);
        $this->assertSame(1, json_decode($daftar->getJSON(), true)['data']['total'] ?? null);
        $this->asUser($admin)->delete("api/v1/presensi/tukin/penyesuaian/{$id}")->assertStatus(200);
        $this->asUser($admin)->delete("api/v1/presensi/tukin/penyesuaian/{$id}")->assertStatus(404);

        $this->clearAuthState();
        $this->assertSame(401, $this->withHeaders([])->get('api/v1/presensi/tukin/penyesuaian')->response()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $timpa
     *
     * @return array<string, mixed>
     */
    private function isi(string $nip, array $timpa = []): array
    {
        return array_merge(['nip' => $nip, 'bulan_mulai' => '2024-04', 'bulan_akhir' => '2024-06', 'persen' => '-20.00', 'alasan' => 'Predikat Kinerja Kurang'], $timpa);
    }

    private function admin(): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) self::ID_ADMIN, 'role' => Role::SUPER_ADMIN]);

        return $auth;
    }

    private function service(): PenyesuaianTukinService
    {
        return new PenyesuaianTukinService($this->db, static fn (): DateTimeInterface => new DateTimeImmutable('2026-10-28 09:00:00', new DateTimeZone('Asia/Jakarta')));
    }
}
