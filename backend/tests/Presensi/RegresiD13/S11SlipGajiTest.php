<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * D-13 S11 — Slip gaji (PRD 5C §7.6, QASMTASK-088 / QAFUNC-006) lewat service & route 5A yang berlaku (tanpa mengubah aturan):
 * - impor: nama di berkas tidak cocok dengan NIP → baris galat `nama_tidak_cocok` + satu `audit_logs` `warning`; NIP +
 *   periode ganda di dalam berkas → galat `baris_ganda` di pratinjau; NIP + periode yang sudah ada → ditandai `duplikat`
 *   di pratinjau; pelanggaran UNIQUE saat commit → 409 `konflik_data` dengan pesan jelas + rollback;
 * - gerbang rilis tanggal 4 pukul 10:00 WIB: 09:59:59 → 422 `periode_belum_rilis`, 10:00:00 → slip dapat dibuka;
 * - pegawai hanya slip sendiri (HTTP): parameter NIP lain diabaikan/ditolak, data yang dibuka milik NIP token.
 * Acuan legacy slip `0cf7e67` (README `Libraries/Presensi`, CR-027/CR-067).
 *
 * Test lama yang dicakup: `Presensi/Slip/ImporSlipMismatchNamaTest`, `ImporSlipDuplikatTest`, `ImporSlipKonflikUniqueTest`,
 * `GerbangSlipTest`, `SlipPegawaiAksesTest`, `RoleMatriksSlipTest`.
 *
 * @internal
 */
#[Group('d13')]
final class S11SlipGajiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->siapkanSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testImporMismatchDuplikatDanUnique(): void
    {
        $admin = $this->adminSlip();

        // Nama tidak cocok → galat + satu audit warning.
        $rina    = $this->buatPegawai(['nama' => 'Rina Wulandari', 'glr_awal' => null, 'glr_akhir' => null]);
        $pratinj = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($rina, 9, 2026, 'Joko Susilo')], [$this->barisTk($rina, 9, 2026, 'Rina')])));
        $this->assertSame('galat', $pratinj['baris'][0]['status']);
        $this->assertContains('nama_tidak_cocok', array_column($pratinj['baris'][0]['galat'], 'kode'));
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'gaji_pegawai_impor')->where('event', 'warning')->countAllResults());

        // NIP + periode ganda di dalam berkas → ditolak di pratinjau.
        $agus  = $this->buatPegawai(['nama' => 'Agus Salim']);
        $ganda = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($agus, 9, 2026, 'Agus Salim'), $this->barisGpp($agus, 9, 2026, 'Agus Salim', ['gjpokok' => 1])],
            [$this->barisTk($agus, 9, 2026, 'Agus Salim')],
        )));
        $this->assertSame(['valid' => 0, 'duplikat' => 0, 'galat' => 1], $ganda['ringkasan']);
        $this->assertSame(['baris_ganda'], array_column($ganda['baris'][0]['galat'], 'kode'));

        // NIP + periode sudah ada → ditandai duplikat di pratinjau.
        $this->db->table('gaji_pegawai')->insert(['nip' => $agus, 'bulan' => 8, 'tahun' => 2026, 'gjpokok' => '1000000.00', 'created_at' => '2026-08-30 00:00:00']);
        $ada = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($agus, 8, 2026, 'Agus Salim')], [$this->barisTk($agus, 8, 2026, 'Agus Salim')])));
        $this->assertSame(['valid' => 0, 'duplikat' => 1, 'galat' => 0], $ada['ringkasan']);

        // Pelanggaran UNIQUE saat commit (proses lain) → 409 pesan jelas, rollback.
        $budi  = $this->buatPegawai(['nama' => 'Budi Dua']);
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($agus, 10, 2026, 'Agus Salim'), $this->barisGpp($budi, 10, 2026, 'Budi Dua')],
            [$this->barisTk($agus, 10, 2026, 'Agus Salim'), $this->barisTk($budi, 10, 2026, 'Budi Dua')],
        )))['token_pratinjau'];
        $kait = static function (BaseConnection $db, string $nip, PeriodeSlip $periode) use ($budi): void {
            if ($nip === $budi) {
                $db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun, 'created_at' => '2026-10-27 00:00:00']);
            }
        };

        try {
            (new ImporSlipService(null, null, null, null, $kait))->commit($this->authImpor($admin), $token, null);
            $this->fail('UNIQUE harus 409');
        } catch (ApiException $e) {
            $this->assertSame([409, KodeGalatSlip::KONFLIK_DATA], [$e->getStatusCode(), $e->getKode()]);
            $this->assertStringContainsString("NIP {$budi} periode Oktober 2026 sudah ditambahkan proses lain", $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('gaji_pegawai')->where(['nip' => $agus, 'bulan' => 10])->countAllResults(), 'rollback seluruh transaksi');
    }

    public function testGerbangTanggalEmpatPukulSepuluhWib(): void
    {
        $nip  = $this->pegawaiSlip()['nip'];
        $auth = $this->authPegawaiSlip($nip);
        $this->slip($nip, 2026, 10);

        try {
            $this->slipPegawai('2026-10-04 09:59:59')->buka($auth, '2026-10', '203.0.113.1');
            $this->fail('sebelum 10:00 WIB harus ditolak');
        } catch (ApiException $e) {
            $this->assertSame([422, 'periode_belum_rilis'], [$e->getStatusCode(), $e->getKode()]);
        }

        $this->seeInDatabase('gaji_pegawai', ['nip' => $nip, 'bulan' => 10, 'viewed_at' => null]);
        $dibuka = $this->slipPegawai('2026-10-04 10:00:00')->buka($auth, '2026-10', '203.0.113.1');
        $this->assertSame('3000000.00', $dibuka['rincian']['gjpokok']);
    }

    public function testPegawaiHanyaSlipSendiriLewatHttp(): void
    {
        $saya = $this->pegawaiSlip('Citra Saya')['nip'];
        $lain = $this->pegawaiSlip('Dodi Lain')['nip'];
        $this->slip($saya, 2026, 9);
        $idLain = $this->slip($lain, 2026, 9, ['gjpokok' => '9999999.00']);
        $akun   = $this->buatAkunTanpaNip('d13' . bin2hex(random_bytes(3)), Role::PEGAWAI, ['nip' => $saya, 'must_change_password' => 0]);

        $res = $this->asUser($akun)->withBodyFormat('json')->post('api/v1/slip-gaji/saya/2026-09/buka', ['nip' => $lain]);
        $res->assertStatus(200);
        $this->assertSame('3000000.00', $this->json($res)['data']['rincian']['gjpokok'], 'slip milik NIP token, bukan parameter');
        $this->seeInDatabase('gaji_pegawai', ['nip' => $lain, 'viewed_at' => null]);
        $this->asUser($akun)->get("api/v1/slip-gaji/{$idLain}")->assertStatus(403);
        $this->asUser($akun)->get("api/v1/slip-gaji/{$idLain}/pdf")->assertStatus(403);
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function authImpor(array $akun): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $akun['id_pengguna'], 'id_pengguna' => (int) $akun['id_pengguna'], 'role' => (int) $akun['user_level'], 'id_satker' => $akun['id_satker'], 'nip' => null]);

        return $auth;
    }
}
