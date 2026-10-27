<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\TokenSlipService;
use App\Libraries\Presensi\Slip\VerifikasiSlipService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.4 (AC-D14.1–.5, .7): verifikasi QR publik tanpa login — token sah (termasuk token lama sebelum buka ulang)
 * → 200 dengan tepat field AS-06 dari snapshot saat terbit (tanpa nominal/NIP/IP/token/id); format salah, token tidak ada,
 * huruf besar, berspasi → 404 dengan pesan & bentuk identik; header `X-Robots-Tag: noindex, nofollow` dan
 * `Cache-Control: no-store` di 200/404/429; permintaan ke-31 dalam 60 detik → 429; IP tidak tersimpan di `audit_logs`
 * maupun tabel 5A; mengubah nama/satker setelah terbit tidak mengubah hasil.
 *
 * @internal
 */
final class VerifikasiSlipTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    private const IP = '198.51.100.77';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        $this->bersihkanSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testTokenSahDanTokenLama(): void
    {
        $nip = $this->pegawaiSlip('Eka Putri')['nip'];
        $id  = $this->slip($nip, 2026, 9);
        $this->slipPegawai('2026-10-27 09:00:00')->buka($this->authPegawaiSlip($nip), '2026-09', self::IP);
        $lama = (string) (new TokenSlipService())->mentah($this->db->table('gaji_pegawai')->select('qr_token_enkripsi')->where('id', $id)->get()->getRowArray()['qr_token_enkripsi']);

        $harapan = ['status_keaslian' => 'asli', 'periode' => '2026-09', 'label_periode' => 'September 2026', 'satker_penerbit' => $this->satker($nip), 'waktu_terbit' => '27 Oktober 2026, 09:00 WIB', 'nama' => 'E*** P***'];
        $this->assertSame($harapan, $this->ok($lama));

        // Data terkini berubah + buka ulang + buka lagi: token lama tetap sah dengan snapshot lama.
        $this->db->table('pegawai')->where('nip', $nip)->update(['nama' => 'Nama Baru']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['satker' => 'Satker Baru']);
        $this->slipAdmin()->bukaUlang($this->authAdminSlip(), $id);
        $this->slipPegawai('2026-10-27 11:00:00')->buka($this->authPegawaiSlip($nip), '2026-09', self::IP);

        $this->assertSame($harapan, $this->ok($lama), 'token lama + snapshot saat terbit');
        $baru = (string) (new TokenSlipService())->mentah($this->db->table('gaji_pegawai')->select('qr_token_enkripsi')->where('id', $id)->get()->getRowArray()['qr_token_enkripsi']);
        $this->assertSame(['satker_penerbit' => 'Satker Baru', 'waktu_terbit' => '27 Oktober 2026, 11:00 WIB', 'nama' => 'N*** B***'], array_intersect_key($this->ok($baru), array_flip(['nama', 'satker_penerbit', 'waktu_terbit'])));

        foreach (['audit_logs', 'gaji_pegawai'] as $tabel) {
            foreach ($this->db->table($tabel)->get()->getResultArray() as $row) {
                if ($tabel === 'gaji_pegawai') {
                    unset($row['viewed_ip']); // IP buka slip [K] disimpan di slip, bukan dari verifikasi
                }

                $this->assertStringNotContainsString('198.51.100.88', (string) json_encode($row), "IP verifikasi tidak tersimpan di {$tabel}");
            }
        }
    }

    public function testTokenTidakSah404Seragam(): void
    {
        $nip = $this->pegawaiSlip()['nip'];
        $this->slip($nip, 2026, 9);
        $this->slipPegawai()->buka($this->authPegawaiSlip($nip), '2026-09', self::IP);
        $sah = (string) (new TokenSlipService())->mentah($this->db->table('gaji_pegawai')->select('qr_token_enkripsi')->get()->getRowArray()['qr_token_enkripsi']);

        $badan = [];

        foreach (['abc', str_repeat('a', 64), strtoupper($sah), ' ' . $sah, $sah . 'x', hash('sha256', $sah)] as $token) {
            $res = $this->panggil(rawurlencode($token));
            $res->assertStatus(404);
            $this->assertHeaderAman($res);
            $badan[] = (string) $res->response()->getBody();
        }

        $this->assertCount(1, array_unique($badan), 'pesan & bentuk identik');
        $this->assertSame(['status' => 'error', 'message' => VerifikasiSlipService::PESAN_TIDAK_DITEMUKAN], json_decode($badan[0], true));
    }

    public function testThrottle429(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->panggil(str_repeat('a', 64))->assertStatus(404);
        }

        $res = $this->panggil(str_repeat('a', 64));
        $res->assertStatus(429);
        $this->assertHeaderAman($res);
        $this->assertGreaterThanOrEqual(1, (int) $res->response()->getHeaderLine('Retry-After'));
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(string $token): array
    {
        $res = $this->panggil($token);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertHeaderAman($res);
        $data = $this->json($res)['data'];

        foreach (['nip', 'ip', 'viewed_ip', 'token', 'qr_token', 'id', 'id_gaji_pegawai', 'gjpokok', 'total_penghasilan'] as $terlarang) {
            $this->assertArrayNotHasKey($terlarang, $data);
        }

        return $data;
    }

    private function panggil(string $token): \CodeIgniter\Test\TestResponse
    {
        service('superglobals')->setServer('REMOTE_ADDR', '198.51.100.88');

        try {
            return $this->withHeaders([])->get('api/v1/publik/verifikasi-slip/' . $token);
        } finally {
            Services::resetSingle('response');
        }
    }

    private function assertHeaderAman(\CodeIgniter\Test\TestResponse $res): void
    {
        $this->assertSame('noindex, nofollow', $res->response()->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
    }

    private function satker(string $nip): string
    {
        return (string) $this->db->table('pegawai_mutasi_jabatan')->select('satker')->where('nip', $nip)->get()->getRowArray()['satker'];
    }
}
