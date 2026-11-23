<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Constants\Role;
use App\Libraries\Kepegawaian\NipCascadeService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * CR-185: pengajuan cuti pemohon — JwtAuthFilter lolos (NIP masih dipegang), lalu NIP pemohon dikoreksi di sela validasi dan
 * tulis (seam `sebelumTransaksi()`): INSERT `riwayat_cuti` ber-NIP lama kena FK `fk_nip_rwycuti_to_pegawai` (1452). Jaring
 * CR-171 hanya untuk approver, jadi dulu 500; kini jaring terpusat ApiController::_remap → 401 body persis filter, tanpa baris.
 *
 * Dipakai jenis Alasan Penting (5): untuk Tahunan, koreksi NIP ikut memindahkan `pegawai_sisa_cuti`, sehingga pemeriksaan ulang
 * saldo di bawah lock sudah menolak 422 `lama_cuti` sebelum INSERT (jalur aman, tanpa 1452).
 *
 * @internal
 */
final class CutiPemohonSesiBasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use CutiUjiTrait;
    use FeatureTestTrait;

    private const NIP_BARU = '198801012010011997';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testKoreksiNipPemohonDiSelaValidasiDanTulis401TanpaBaris(): void
    {
        $a    = $this->buatAlurDuaLangkah();
        $lama = $a['pemohon']['nip'];
        $this->db->table('pengguna')->where('id_pengguna', $a['pemohon']['id_pengguna'])->update(['must_change_password' => 0]);
        $token = $this->issueTokensForUser((array) $this->db->table('pengguna')->where('id_pengguna', $a['pemohon']['id_pengguna'])->get()->getRowArray())['access_token'];
        $super = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN));

        Services::injectMock('cutiService', $this->cutiService(null, static function () use ($super, $lama): void {
            (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);
        }));

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->withBodyFormat('json')
            ->post('api/v1/cuti', $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['id_jenis_cuti' => 5]));

        $res->assertStatus(401);
        $this->assertSame(['status' => 'error', 'message' => 'Unauthorized'], json_decode($res->getJSON(), true));
        $this->assertSame(0, $this->db->table('riwayat_cuti')->where('nip', $lama)->countAllResults(), 'tanpa baris ber-NIP lama');
        $this->assertSame(0, $this->db->table('riwayat_cuti')->where('nip', self::NIP_BARU)->countAllResults());
    }
}
