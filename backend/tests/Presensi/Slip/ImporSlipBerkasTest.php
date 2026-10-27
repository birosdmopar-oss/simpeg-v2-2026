<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.2 butir 2–3 & 5, §7.2.5 (AC-D10.7, AC-D10.10): keamanan unggah impor Slip Gaji — berkas wajib, ekstensi
 * `.xlsx`/`.xls` saja, tanpa ekstensi ditolak, ukuran ≤ batas, isi ≙ ekstensi (pesan PRD), direktori pratinjau di bawah
 * `writable/` (bukan `public/`), tidak ada berkas tersimpan saat ditolak, dan TTL: pratinjau kedaluwarsa → commit 404
 * `pratinjau_tidak_ditemukan` + berkas terhapus.
 *
 * @internal
 */
final class ImporSlipBerkasTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->nip = $this->buatPegawai(['nama' => 'Fajar Nugroho']);
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->maksUkuranImporByte = 5 * 1024 * 1024;
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testDirektoriBawaanDiWritable(): void
    {
        $bawaan = (new Presensi())->direktoriPratinjau;

        $this->assertStringStartsWith(WRITEPATH, $bawaan);
        $this->assertStringNotContainsString(rtrim(FCPATH, '/\\'), $bawaan, 'bukan di public/');
    }

    public function testBerkasDitolak(): void
    {
        $admin = $this->adminSlip();
        $xlsx  = fn (string $nama) => $this->berkasSlip([$this->barisGpp($this->nip, 9, 2026, 'Fajar')], [$this->barisTk($this->nip, 9, 2026, 'Fajar')], namaKlien: $nama);
        $kasus = [
            'tanpa berkas'      => [null, 'Berkas wajib diunggah.'],
            'ekstensi csv'      => [$this->berkasMentah("nip,bulan\n1,9\n", 'slip.csv'), ImporSlipService::PESAN_FORMAT],
            'tanpa ekstensi'    => [$xlsx('slip'), 'Isi berkas tidak sesuai dengan ekstensinya.'],
            'pdf berkedok xlsx' => [$this->berkasMentah("%PDF-1.4\n%%EOF", 'slip.xlsx'), ImporSlipService::PESAN_FORMAT], // jenis dibaca dari isi
            'xlsx berkedok xls' => [$xlsx('slip.xls'), 'Isi berkas tidak sesuai dengan ekstensinya.'],
        ];

        foreach ($kasus as $label => [$berkas, $pesan]) {
            $res = $this->unggahSlip($admin, $berkas);
            $res->assertStatus(422);
            $this->assertSame([$pesan], $this->json($res)['errors']['berkas'] ?? null, $label);
        }

        config(Presensi::class)->maksUkuranImporByte = 1024;
        $res                                         = $this->unggahSlip($admin, $xlsx('besar.xlsx'));
        $res->assertStatus(422);
        $this->assertSame(['Ukuran berkas maksimal 0,0 MB.'], $this->json($res)['errors']['berkas']);

        config(Presensi::class)->maksUkuranImporByte = 5 * 1024 * 1024;
        $this->assertSame([], $this->isiDirPratinjau(), 'berkas ditolak tidak pernah disimpan');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    public function testPesanUkuranBawaan5Mb(): void
    {
        $this->assertSame('Ukuran berkas maksimal 5 MB.', $this->pesanUkuran(5 * 1024 * 1024));
    }

    public function testTtlKedaluwarsa(): void
    {
        $admin = $this->adminSlip();
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Fajar')],
            [$this->barisTk($this->nip, 9, 2026, 'Fajar')],
        )))['token_pratinjau'];
        $this->assertCount(2, $this->isiDirPratinjau());

        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $admin['id_pengguna'], 'role' => (int) $admin['user_level'], 'id_satker' => $admin['id_satker']]);
        $nanti = time() + config(Presensi::class)->ttlPratinjauDetik + 1;

        try {
            (new ImporSlipService(null, null, null, static fn (): int => $nanti))->commit($auth, $token, null);
            $this->fail('pratinjau kedaluwarsa harus 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame(KodeGalatSlip::PRATINJAU_TIDAK_DITEMUKAN, $e->getKode());
            $this->assertSame(ImporSlipService::PESAN_PRATINJAU_TIDAK_ADA, $e->getMessage());
        }

        $this->assertSame([], $this->isiDirPratinjau(), 'berkas kedaluwarsa disapu');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    private function pesanUkuran(int $byte): string
    {
        config(Presensi::class)->maksUkuranImporByte = $byte;

        $admin = $this->adminSlip();
        $res   = $this->unggahSlip($admin, $this->berkasMentah(str_repeat('x', $byte + 1), 'besar.xlsx'));

        return (string) ($this->json($res)['errors']['berkas'][0] ?? '');
    }
}
