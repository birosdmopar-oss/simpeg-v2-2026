<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.4 butir 5 (AC-D10.4): pelanggaran UNIQUE `(nip, bulan, tahun)` saat commit (proses lain menyisipkan kunci
 * yang sama setelah validasi ulang) → 409 `konflik_data` dengan NIP + periode, SELURUH transaksi di-rollback (baris yang
 * sudah disisip lebih dulu ikut batal), tanpa audit `import`, berkas pratinjau dihapus — tidak pernah 500. Proses lain
 * disimulasikan lewat seam `kaitSebelumTulis` (PRD §7.2.6 "boleh memakai seam/double yang melempar 1062").
 *
 * @internal
 */
final class ImporSlipKonflikUniqueTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testKonflik1062Jadi409DanRollback(): void
    {
        $a     = $this->buatPegawai(['nama' => 'Ahmad Satu']);
        $b     = $this->buatPegawai(['nama' => 'Budi Dua']);
        $admin = $this->adminSlip();
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($a, 9, 2026, 'Ahmad Satu'), $this->barisGpp($b, 9, 2026, 'Budi Dua')],
            [$this->barisTk($a, 9, 2026, 'Ahmad Satu'), $this->barisTk($b, 9, 2026, 'Budi Dua')],
        )))['token_pratinjau'];
        $sebelum = $this->db->table('gaji_pegawai')->countAllResults();

        // Proses lain menyisipkan kunci NIP kedua tepat sebelum service menulisnya.
        $kait = static function (BaseConnection $db, string $nip, PeriodeSlip $periode) use ($b): void {
            if ($nip === $b) {
                $db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun, 'created_at' => '2026-10-27 00:00:00']);
            }
        };

        try {
            (new ImporSlipService(null, null, null, null, $kait))->commit($this->auth($admin), $token, null);
            $this->fail('1062 harus menjadi 409');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(KodeGalatSlip::KONFLIK_DATA, $e->getKode());
            $this->assertSame("Data slip NIP {$b} periode September 2026 sudah ditambahkan proses lain. Tidak ada data yang disimpan; ulangi unggah dan pratinjau.", $e->getMessage());
            $this->assertSame(['nip_periode' => ["{$b} 2026-09"]], $e->getErrors());
        }

        $this->assertSame($sebelum, $this->db->table('gaji_pegawai')->countAllResults(), 'seluruh transaksi di-rollback, termasuk NIP pertama');
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'gaji_pegawai_impor')->where('event', 'import')->countAllResults());
        $this->assertSame([], $this->isiDirPratinjau(), 'berkas pratinjau dihapus walau 409');
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function auth(array $akun): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $akun['id_pengguna'], 'id_pengguna' => (int) $akun['id_pengguna'], 'role' => (int) $akun['user_level'], 'id_satker' => $akun['id_satker'], 'nip' => null]);

        return $auth;
    }
}
