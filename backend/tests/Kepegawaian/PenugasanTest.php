<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Struktur\PenugasanService;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * Penugasan Plt/Plh (B-07, WS-2 M4 MAKE-012): validasi legacy validate_param_off_plt/vp_off_plh, hak akses
 * (lihat 1/3/4/5, tulis 1), lingkup role 3 (satker akun), lampiran kolom, hapus lunak status 10, endpoint.
 *
 * @internal
 */
final class PenugasanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use RiwayatUjiTrait {
        LampiranUjiTrait::berkasUji insteadof RiwayatUjiTrait;
    }

    /**
     * @var array{id_unit: int, id_satker: int, id_group_jabatan: int, id_sub_group_jabatan: int, id_jabatan: int}
     */
    private array $jab;

    private string $nipPlt;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        $this->jab    = $this->buatJabatan();
        $this->nipPlt = $this->buatPegawai();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testTambahUbahHapusPltDenganLampiran(): void
    {
        $admin = $this->admin();
        $row   = $this->svc()->tambah($admin, 'plt', $this->data(), $this->berkasPdf('sk.pdf'));

        $this->assertSame([$this->nipPlt, 1, true], [$row['nip'], (int) $row['status'], $row['ada_lampiran']]);
        $this->assertArrayNotHasKey('lampiran', $row, 'path berkas tidak dibocorkan');
        $this->assertSame((string) $this->jab['id_jabatan'], (string) $row['id_jabatan']);
        $this->assertSame('%PDF', substr($this->svc()->unduhLampiran($admin, 'plt', $row['id'])['isi'], 0, 4));

        $ubah = $this->svc()->ubah($admin, 'plt', $row['id'], ['status' => 2] + $this->data(), null);
        $this->assertSame(2, (int) $ubah['status']);
        $this->assertTrue($ubah['ada_lampiran'], 'lampiran lama dipertahankan bila tidak diganti');

        $this->svc()->hapus($admin, 'plt', $row['id']);
        $this->assertSame('10', (string) $this->db->table('pegawai_plt')->where('id_pegawai_plt', $row['id'])->get()->getRow()->status);
        $this->assertSame(0, $this->svc()->daftar($admin, 'plt')['meta']['total']);
        $this->assertStatusApi(404, fn () => $this->svc()->detail($admin, 'plt', $row['id']));
        $this->assertNotSame([], $this->auditUji('pegawai_plt', (string) $row['id']));
    }

    public function testPlhKoordinasiDanValidasiLegacy(): void
    {
        $admin = $this->admin();

        $this->assertErrors(
            ['status', 'jenis_jabatan', 'tgl_mulai', 'tgl_akhir', 'id_unit', 'id_satker', 'id_group_jabatan_plh', 'id_sub_group_jabatan_plh', 'id_unit_plh', 'id_satker_plh', 'nip_plh'],
            fn () => $this->svc()->tambah($admin, 'plh', [], null),
        );
        $this->assertErrors(['tgl_akhir'], fn () => $this->svc()->tambah($admin, 'plh', ['tgl_mulai' => '2026-02-01', 'tgl_akhir' => '2026-01-01'] + $this->data('plh'), null));
        $this->assertErrors(['id_jabatan'], fn () => $this->svc()->tambah($admin, 'plh', ['id_jabatan' => 999999] + $this->data('plh'), null));

        $koord = $this->buatJabatanKoordinasi($this->jab['id_satker'], ['jenis' => 2]);
        $data  = ['jenis_jabatan' => 2, 'jenis_jabatan_koord' => 1, 'id_jabatan_koord' => $koord] + $this->data('plh');
        $this->assertErrors(['id_jabatan_koord'], fn () => $this->svc()->tambah($admin, 'plh', $data, null));

        $row = $this->svc()->tambah($admin, 'plh', ['jenis_jabatan_koord' => 2] + $data, null);
        $this->assertNull($row['id_jabatan'], 'jabatan nomenklatur dikosongkan untuk jabatan koordinasi');
        $this->assertSame((string) $koord, (string) $row['id_jabatan_koord']);
    }

    public function testHakAksesDanLingkupRole3(): void
    {
        $id = $this->svc()->tambah($this->admin(), 'plt', $this->data(), null)['id'];
        // Penugasan di satker lain.
        $lain = $this->buatJabatan();
        $this->svc()->tambah($this->admin(), 'plt', ['id_unit' => $lain['id_unit'], 'id_satker' => $lain['id_satker'], 'id_jabatan' => $lain['id_jabatan'],
            'id_group_jabatan'                                 => $lain['id_group_jabatan'], 'id_sub_group_jabatan' => $lain['id_sub_group_jabatan']] + $this->data(), null);

        $role3 = $this->aktor(Role::ADMIN_SATKER, [], $this->jab['id_satker'])[1];
        $this->assertSame([$id], array_column($this->svc()->daftar($role3, 'plt')['items'], 'id'));
        $this->assertFalse($this->svc()->daftar($role3, 'plt')['can_create']);
        $this->assertStatusApi(403, fn () => $this->svc()->tambah($role3, 'plt', $this->data(), null));

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            $this->assertSame(2, $this->svc()->daftar($this->aktor($role)[1], 'plt')['meta']['total']);
        }

        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $this->assertStatusApi(403, fn () => $this->svc()->daftar($this->aktor($role)[1], 'plt'));
        }
    }

    public function testEndpoint(): void
    {
        [, , $super]   = $this->aktor(Role::SUPER_ADMIN);
        [, , $pegawai] = $this->aktor(Role::PEGAWAI);

        $res = $this->asUser($super)->withBodyFormat('json')->post('api/v1/penugasan/plt', $this->data());
        $res->assertStatus(201);
        $id = $this->json($res)['data']['id'];

        $this->asUser($super)->get('api/v1/penugasan/plt')->assertStatus(200);
        $this->asUser($super)->withBodyFormat('json')->put("api/v1/penugasan/plt/{$id}", ['status' => 2] + $this->data())->assertStatus(200);
        $this->asUser($super)->get("api/v1/penugasan/plt/{$id}/lampiran")->assertStatus(404);
        $this->asUser($pegawai)->get('api/v1/penugasan/plt')->assertStatus(403);
        $this->asUser($super)->delete("api/v1/penugasan/plt/{$id}")->assertStatus(200);
    }

    /**
     * @return array<string, int|string>
     */
    private function data(string $jenis = 'plt'): array
    {
        return [
            'status'                        => 1,
            'jenis_jabatan'                 => 1,
            'tgl_mulai'                     => '2026-01-01',
            'tgl_akhir'                     => '2026-12-31',
            'id_group_jabatan'              => $this->jab['id_group_jabatan'],
            'id_sub_group_jabatan'          => $this->jab['id_sub_group_jabatan'],
            'id_jabatan'                    => $this->jab['id_jabatan'],
            'id_unit'                       => $this->jab['id_unit'],
            'id_satker'                     => $this->jab['id_satker'],
            "id_group_jabatan_{$jenis}"     => $this->jab['id_group_jabatan'],
            "id_sub_group_jabatan_{$jenis}" => $this->jab['id_sub_group_jabatan'],
            "id_unit_{$jenis}"              => $this->jab['id_unit'],
            "id_satker_{$jenis}"            => $this->jab['id_satker'],
            "nip_{$jenis}"                  => $this->nipPlt,
        ];
    }

    private function svc(): PenugasanService
    {
        return new PenugasanService(null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), (string) json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }

    private function assertStatusApi(int $status, Closure $aksi): void
    {
        try {
            $aksi();
            $this->fail("Diharapkan {$status}");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
