<?php

declare(strict_types=1);

namespace Tests\Support\Integrasi;

use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Services;

/**
 * MAKE-059 — perkakas test endpoint kompatibilitas SIMAPI/`services/*` lewat route sungguhan (FeatureTestTrait wajib
 * dipakai kelas test): cache/throttler/log tiruan, klien layanan uji, token lewat handshake `a_auth/token` asli, dan
 * fixture kontrak JSON di `tests/_support/Integrasi/Simapi/`.
 *
 * Panggil {@see pasangSimapiUji()} di setUp dan {@see lepasSimapiUji()} di tearDown.
 */
trait SimapiUjiTrait
{
    protected LogIntegrasiTiruan $logSimapi;

    protected function pasangSimapiUji(): void
    {
        Services::injectMock('cache', new MockCache());
        // cacheService/webConfigService bersama membungkus cache dari test sebelumnya (nilai web_config + id referensi
        // yang tidak ikut rollback) — buat ulang di atas MockCache segar agar test tidak bergantung urutan.
        Services::resetSingle('cacheService');
        Services::resetSingle('webConfigService');
        $this->logSimapi = new LogIntegrasiTiruan();
        Services::injectMock('integrasiLog', $this->logSimapi);
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
        Services::superglobals()->setServer('REMOTE_ADDR', '203.0.113.9');
    }

    protected function lepasSimapiUji(): void
    {
        Services::resetSingle('cache');
        Services::resetSingle('cacheService');
        Services::resetSingle('webConfigService');
        Services::resetSingle('integrasiLog');
        Services::resetSingle('throttler');
        Services::resetSingle('klienSaatIni');
        Services::superglobals()->setServer('REMOTE_ADDR', '127.0.0.1');
    }

    /**
     * Klien layanan uji aktif.
     *
     * @param list<string> $hak
     *
     * @return array{id: int, username: string, rahasia: string}
     */
    protected function klienUji(string $username, int $jenis, array $hak): array
    {
        $r = (new PenyimpanKlien($this->db))->buat('Sistem Uji', $username, $jenis, $hak, null);

        return ['id' => $r['id'], 'username' => $username, 'rahasia' => $r['rahasia']];
    }

    /**
     * @param array{username: string, rahasia: string} $klien
     */
    protected function basicUji(array $klien): string
    {
        return 'Basic ' . base64_encode($klien['username'] . ':' . $klien['rahasia']);
    }

    /**
     * Token SIMAPI lewat handshake asli `simapi/v1/a_auth/token`.
     *
     * @param array{username: string, rahasia: string} $klien
     */
    protected function tokenUji(array $klien): string
    {
        $badan = $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($klien)])->call('post', 'simapi/v1/a_auth/token'));

        $this->assertSame('01', $badan['status'] ?? null, 'handshake a_auth/token');

        return (string) $badan['data']['token'];
    }

    /**
     * GET endpoint SIMAPI dengan token (skema bebas, elemen kedua header [K `AE:30-47`]).
     */
    protected function simapiUji(string $path, string $token, string $method = 'get'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])->call($method, $path);
    }

    /**
     * Panggilan TANPA header Authorization (header FeatureTestTrait bertahan antar-panggilan, jadi dikosongkan eksplisit).
     */
    protected function tanpaAuthUji(string $path, string $method = 'get'): TestResponse
    {
        return $this->withHeaders(['Authorization' => ''])->call($method, $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonUji(TestResponse $res): array
    {
        $res->assertStatus(200);
        $badan = json_decode((string) $res->getJSON(), true);
        $this->assertIsArray($badan);

        return $badan;
    }

    /**
     * Fixture kontrak (`tests/_support/Integrasi/Simapi/<berkas>.json`).
     *
     * @return array<string, mixed>
     */
    protected function fixtureKontrak(string $berkas): array
    {
        $isi = json_decode((string) file_get_contents(SUPPORTPATH . 'Integrasi/Simapi/' . $berkas . '.json'), true);
        $this->assertIsArray($isi, $berkas);

        return $isi;
    }
}
