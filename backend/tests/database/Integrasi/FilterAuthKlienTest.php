<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\KlienLayanan;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-058 (A3) — filter `klienLayanan:<hak>` dan `simapiToken` lewat pipeline route sungguhan (FeatureTestTrait):
 * AC-F.1..F.5 pada lapisan HTTP — amplop gagal per grup (SIMAPI 200 "02" / services 401 int 2), pesan seragam untuk semua
 * alasan, log akses masuk tanpa rahasia/IP, throttle 429, dan identitas klien tersedia di `klienSaatIni`.
 *
 * @internal
 */
final class FilterAuthKlienTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const IP = '203.0.113.9';

    private LogIntegrasiTiruan $log;

    private string $rahasia = '';

    private int $idKlien = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new LogIntegrasiTiruan();
        Services::injectMock('integrasiLog', $this->log);
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
        Services::superglobals()->setServer('REMOTE_ADDR', self::IP);

        $this->withRoutes([
            ['get', '__uji/stat', static fn () => service('response')->setJSON(['id' => service('klienSaatIni')->id(), 'jenis' => service('klienSaatIni')->jenis()]), ['filter' => 'klienLayanan:stat']],
            ['get', '__uji/simapi-auth', static fn () => service('response')->setJSON(['id' => service('klienSaatIni')->id()]), ['filter' => 'klienLayanan:simapi']],
            ['get', '__uji/simapi-data', static fn () => service('response')->setJSON(['status' => '01', 'id' => service('klienSaatIni')->id()]), ['filter' => 'simapiToken']],
        ]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('integrasiLog');
        Services::resetSingle('throttler');
        Services::resetSingle('klienSaatIni');
        Services::superglobals()->setServer('REMOTE_ADDR', '127.0.0.1');
        parent::tearDown();
    }

    /**
     * @param list<string> $hak
     */
    private function klien(string $username, int $jenis, array $hak, ?string $ip = null): void
    {
        $r             = (new PenyimpanKlien($this->db))->buat('Sistem Uji', $username, $jenis, $hak, $ip);
        $this->rahasia = $r['rahasia'];
        $this->idKlien = $r['id'];
    }

    private function basic(string $username, string $rahasia): string
    {
        return 'Basic ' . base64_encode($username . ':' . $rahasia);
    }

    public function testServicesBerhasilMembawaIdentitasKlien(): void
    {
        $this->klien('mudig', 3, ['stat']);

        $res = $this->withHeaders(['Authorization' => $this->basic('mudig', $this->rahasia)])->call('get', '__uji/stat');

        $res->assertStatus(200);
        $this->assertSame(['id' => $this->idKlien, 'jenis' => 3], json_decode($res->getJSON(), true));
        $this->assertSame([], $this->log->isi, 'sukses tidak ditulis ke log (hanya kegagalan)');
    }

    public function testServicesGagalSeragam401DenganAmplopInt(): void
    {
        $this->klien('mudig', 3, ['stat']);
        $kasus = [
            ['', []],
            ['Bearer abc', []],
            ['Basic !!!', []],
            ['Basic ' . base64_encode('mudig'), []],
            [$this->basic('mudig', 'salah'), []],
            [$this->basic('tidak-ada', $this->rahasia), []],
            ['Basic ' . str_repeat('A', 600), []],
        ];

        foreach ($kasus as [$header]) {
            $res = $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat');

            $res->assertStatus(401);
            $this->assertSame(['status' => 2, 'msg' => 'Authentication failed', 'data' => []], json_decode($res->getJSON(), true));
            $this->assertStringContainsString('Basic', $res->response()->getHeaderLine('WWW-Authenticate'));
        }
    }

    public function testSimapiGagalHttp200DenganStatus02(): void
    {
        $this->klien('simanta', 2, ['simapi']);

        $res = $this->withHeaders(['Authorization' => $this->basic('simanta', 'salah')])->call('get', '__uji/simapi-auth');

        $res->assertStatus(200);
        $this->assertSame(['status' => '02', 'msg' => 'Authentication failed', 'data' => []], json_decode($res->getJSON(), true));
    }

    public function testKlienTanpaHakIpDitolakDanNonaktifDitolak(): void
    {
        $this->klien('mudig', 3, ['stat'], '198.51.100.0/24');
        $res = $this->withHeaders(['Authorization' => $this->basic('mudig', $this->rahasia)])->call('get', '__uji/stat');
        $res->assertStatus(401);

        $this->klien('dm-saja', 3, ['dm']);
        $res = $this->withHeaders(['Authorization' => $this->basic('dm-saja', $this->rahasia)])->call('get', '__uji/stat');
        $res->assertStatus(401);

        $this->klien('stat-ok', 3, ['stat']);
        $header = $this->basic('stat-ok', $this->rahasia);
        $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat')->assertStatus(200);

        (new PenyimpanKlien($this->db))->ubahStatus('stat-ok', PenyimpanKlien::STATUS_NONAKTIF);
        $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat')->assertStatus(401);
    }

    public function testLogAksesMasukTanpaRahasiaUsernameAtauIp(): void
    {
        $this->klien('mudig', 3, ['stat']);
        $this->withHeaders(['Authorization' => $this->basic('mudig', 'rahasia-salah-xyz')])->call('get', '__uji/stat');
        $this->withHeaders(['Authorization' => $this->basic('tidak-ada', 'x')])->call('get', '__uji/stat');

        $this->assertCount(2, $this->log->isi);
        [$satu, $dua] = $this->log->isi;
        $this->assertSame([CatatanIntegrasi::ARAH_MASUK, 'services', 'auth_klien', CatatanIntegrasi::GAGAL, 401, 'tidak_sah'], [$satu->arah, $satu->layanan, $satu->operasi, $satu->status, $satu->httpStatus, $satu->kode]);
        $this->assertSame($this->idKlien, $satu->idKlien, 'username dikenal → id klien tercatat');
        $this->assertNull($dua->idKlien, 'username tak dikenal → tanpa id');

        $semua = $this->log->semuaTeks();
        foreach (['rahasia-salah-xyz', 'mudig', self::IP, $this->rahasia] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $semua, "log memuat {$terlarang}");
        }
    }

    public function testTokenSimapiMelewatkanKlienDanMenolakSisanya(): void
    {
        $this->klien('simanta', 2, ['simapi']);
        $token = (new TokenSimapi($this->db))->terbitkan($this->idKlien)['token'];

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->call('get', '__uji/simapi-data');
        $res->assertStatus(200);
        $this->assertSame(['status' => '01', 'id' => $this->idKlien], json_decode($res->getJSON(), true));

        foreach (['', 'Bearer', 'Bearer salah', 'Bearer ' . strtoupper($token), 'Bearer ' . $token . 'x'] as $header) {
            $res = $this->withHeaders(['Authorization' => $header])->call('get', '__uji/simapi-data');
            $res->assertStatus(200);
            $this->assertSame(['status' => '02', 'msg' => 'Token is not valid', 'data' => []], json_decode($res->getJSON(), true), "header [{$header}]");
        }

        $this->assertStringNotContainsString($token, $this->log->semuaTeks());
    }

    public function testTokenKedaluwarsaDanDicabutMengembalikanTokenNotValid(): void
    {
        $this->klien('simanta', 2, ['simapi']);
        $token = (new TokenSimapi($this->db))->terbitkan($this->idKlien)['token'];
        $this->db->table('simapi_token')->update(['expired_at' => gmdate('Y-m-d H:i:s', time() - 5)]);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->call('get', '__uji/simapi-data');
        $this->assertSame('Token is not valid', json_decode($res->getJSON(), true)['msg'], 'AC-F.5 kedaluwarsa');

        $this->db->table('simapi_token')->update(['expired_at' => gmdate('Y-m-d H:i:s', time() + 600)]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->call('get', '__uji/simapi-data')->assertStatus(200);

        (new PenyimpanKlien($this->db))->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->call('get', '__uji/simapi-data');
        $this->assertSame('Token is not valid', json_decode($res->getJSON(), true)['msg'], 'AC-F.5 klien nonaktif / token dicabut');
    }

    public function testThrottlePerKlienDanPerIp429(): void
    {
        $cfg                         = config(KlienLayanan::class);
        $cfg->throttleKlienKapasitas = 2;
        $cfg->throttleIpKapasitas    = 5;
        $this->klien('mudig', 3, ['stat']);
        $header = $this->basic('mudig', $this->rahasia);

        $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat')->assertStatus(200);
        $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat')->assertStatus(200);
        $res = $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat');
        $res->assertStatus(429);
        $this->assertGreaterThanOrEqual(1, (int) $res->response()->getHeaderLine('Retry-After'));
        $this->assertSame('Too many requests', json_decode($res->getJSON(), true)['msg']);

        // IP: lima percobaan gagal menghabiskan kapasitas IP, berikutnya 429 walau rahasia benar.
        Services::injectMock('throttler', (new Throttler(new MockCache()))->setTestTime(1_790_000_100));
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['Authorization' => $this->basic('mudig', 'salah')])->call('get', '__uji/stat')->assertStatus(401);
        }

        $this->withHeaders(['Authorization' => $header])->call('get', '__uji/stat')->assertStatus(429);
    }
}
