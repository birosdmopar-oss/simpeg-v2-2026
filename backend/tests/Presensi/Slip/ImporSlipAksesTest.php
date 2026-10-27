<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §6.2, §7.2.2 butir 1 (AC-D10.6, SG-2): role 1 mengimpor semua NIP; role 3 hanya NIP di satker akunnya
 * (lainnya galat `nip_di_luar_cakupan`, tetap 200), role 3 tanpa `id_satker` → 403 untuk pratinjau dan commit
 * (fail-closed); role 2/4/5/6/7/8 → 403 di route; tanpa token → 401.
 *
 * @internal
 */
final class ImporSlipAksesTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private int $satker;

    private string $dalam;

    private string $luar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->satker = $this->buatSatker();
        $this->dalam  = $this->buatPegawaiDiSatker($this->satker, ['nama' => 'Dewi Dalam']);
        $this->luar   = $this->buatPegawaiDiSatker($this->buatSatker(), ['nama' => 'Eko Luar']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRole1SemuaNip(): void
    {
        $data = $this->dataOk($this->unggahSlip($this->adminSlip(Role::SUPER_ADMIN, null), $this->berkas()));

        $this->assertSame(['valid' => 2, 'duplikat' => 0, 'galat' => 0], $data['ringkasan']);
    }

    public function testRole3HanyaSatkerSendiri(): void
    {
        $admin = $this->adminSlip(Role::ADMIN_SATKER, (string) $this->satker);
        $data  = $this->dataOk($this->unggahSlip($admin, $this->berkas()));
        $per   = array_column($data['baris'], null, 'nip');

        $this->assertSame('valid', $per[$this->dalam]['status']);
        $this->assertSame(['nip_di_luar_cakupan'], array_column($per[$this->luar]['galat'], 'kode'));

        $hasil = $this->dataOk($this->commitSlip($admin, $data['token_pratinjau']));
        $this->assertSame([1, 1], [$hasil['disisip'], $hasil['gagal']]);
        $this->assertSame(0, $this->db->table('gaji_pegawai')->where('nip', $this->luar)->countAllResults());
    }

    public function testRole3TanpaSatker403(): void
    {
        $tanpa = $this->adminSlip(Role::ADMIN_SATKER, '');
        $this->unggahSlip($tanpa, $this->berkas())->assertStatus(403);
        $this->assertSame([], $this->isiDirPratinjau(), 'tidak ada berkas tersimpan');

        // Pratinjau milik akun yang sama sebelum satkernya dikosongkan tetap tidak bisa di-commit.
        $admin = $this->adminSlip(Role::ADMIN_SATKER, (string) $this->satker);
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkas()))['token_pratinjau'];
        $this->db->table('pengguna')->where('id_pengguna', $admin['id_pengguna'])->update(['id_satker' => null]);
        $admin['id_satker'] = null;

        $this->commitSlip($admin, $token)->assertStatus(403);
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    public function testRoleLain403DanTanpaToken401(): void
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->adminSlip($role, (string) $this->satker);
            $this->unggahSlip($akun, $this->berkas())->assertStatus(403);
            $this->commitSlip($akun, str_repeat('a', 64))->assertStatus(403);
        }

        $this->clearAuthState();
        $this->withHeaders([])->post('api/v1/slip-gaji/impor/pratinjau')->assertStatus(401);
        $this->assertSame([], $this->isiDirPratinjau());
    }

    private function berkas(): \CodeIgniter\HTTP\Files\UploadedFile
    {
        return $this->berkasSlip(
            [$this->barisGpp($this->dalam, 9, 2026, 'Dewi Dalam'), $this->barisGpp($this->luar, 9, 2026, 'Eko Luar')],
            [$this->barisTk($this->dalam, 9, 2026, 'Dewi Dalam'), $this->barisTk($this->luar, 9, 2026, 'Eko Luar')],
        );
    }
}
