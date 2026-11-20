<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-167 — commit impor slip membaca dan menguraikan berkas SEBELUM transaksi dibuka. Galat parse saat commit tidak
 * menjalankan kueri apa pun dan tidak me-rollback transaksi milik pemanggil; commit yang lolos parse tetap menulis di
 * dalam satu transaksi. Status dan pesan galat dikunci test pagar `ImporSlipParitasTest`. Data & NIP sintetis.
 *
 * @internal
 */
final class ImporSlipParseSebelumTransaksiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private int $maksBaris;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->maksBaris = config(Presensi::class)->maksBarisImporPerSheet;
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->maksBarisImporPerSheet = $this->maksBaris;
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testGalatParseSaatCommitTanpaKueriDanTanpaRollbackPemanggil(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        // Baris milik "pemanggil": ditulis sebelum commit impor, harus tetap ada sesudah commit gagal di tahap parse.
        $this->db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => 1, 'tahun' => 2026, 'created_at' => '2026-02-01 00:00:00']);
        $kedalaman                                      = $this->db->transDepth;
        config(Presensi::class)->maksBarisImporPerSheet = 0;

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            (new ImporSlipService())->commit($this->auth($admin), $token, true);
            $this->fail('harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, ['berkas' => ['Jumlah baris melebihi batas 0 per sheet.']]], [$e->getStatusCode(), $e->getErrors()]);
        } finally {
            $hitung->selesai();
        }

        $this->assertSame([], array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => str_contains($sql, 'gaji_pegawai') || str_contains($sql, 'FOR UPDATE'))), 'parse gagal sebelum kueri kunci');
        $this->assertSame($kedalaman, $this->db->transDepth, 'kedalaman transaksi pemanggil tidak berubah');
        $this->assertTrue($this->db->transStatus());
        $this->assertSame(1, $this->db->table('gaji_pegawai')->where('nip', $nip)->countAllResults(), 'baris pemanggil tidak ikut di-rollback');
        $this->assertSame([], $this->isiDirPratinjau());
    }

    public function testCommitLolosParseTetapSatuTransaksiDenganKunci(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $kedalaman             = $this->db->transDepth;
        $dalam                 = null;
        $kait                  = function () use (&$dalam): void {
            $dalam ??= $this->db->transDepth;
        };

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            $hasil = (new ImporSlipService(null, null, null, null, $kait))->commit($this->auth($admin), $token, true);
        } finally {
            $hitung->selesai();
        }

        $this->assertSame([1, 0], [$hasil['disisip'], $hasil['diperbarui']]);
        $this->assertSame($kedalaman + 1, $dalam, 'tulis berlangsung di dalam transaksi commit');
        $this->assertSame($kedalaman, $this->db->transDepth);
        $kunci = array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE')));
        $this->assertCount(1, $kunci);
        $this->assertStringContainsString($nip, $kunci[0]);
        $this->assertSame(1, $this->db->table('gaji_pegawai')->where('nip', $nip)->countAllResults());
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function siapkan(): array
    {
        $nip   = $this->buatPegawai(['nama' => 'Dewi Empat']);
        $admin = $this->adminSlip();
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($nip, 9, 2026, 'Dewi Empat')], [$this->barisTk($nip, 9, 2026, 'Dewi Empat')])))['token_pratinjau'];
        Services::resetSingle('response');

        return [$nip, $admin, (string) $token];
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
