<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\OpsiTransport;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-063 — klien HTTP keluar (PRD Fase 6 §7.0.2): AC-K.1 tanpa nilai rahasia di log, AC-K.2 GET di-retry maks 3 / POST
 * tidak, AC-K.3 timeout tercatat sebagai kegagalan. Tanpa jaringan (FakeTransport), tanpa jeda nyata (tidur diinjeksi).
 *
 * @internal
 */
final class KlienHttpTest extends CIUnitTestCase
{
    private FakeTransport $transport;

    private LogIntegrasiTiruan $penampung;

    /** @var list<int> */
    private array $jeda = [];

    private KlienHttp $klien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->jeda      = [];
        $this->penampung = new LogIntegrasiTiruan();
        $this->klien     = new KlienHttp($this->transport, $this->penampung, tidur: function (int $ms): void {
            $this->jeda[] = $ms;
        }, jitter: static fn (int $maks): int => 0);
    }

    public function testGetSuksesSekaliTercatatSukses(): void
    {
        $this->transport->antreJson(200, ['success' => 1]);

        $r = $this->klien->kirim($this->get());

        $this->assertSame(200, $r->status);
        $this->assertSame(['success' => 1], $r->json());
        $this->assertCount(1, $this->log());
        $this->assertSame([CatatanIntegrasi::ARAH_KELUAR, 'bkn', 'periode', CatatanIntegrasi::SUKSES, 200, null, 1], [
            $this->log()[0]->arah, $this->log()[0]->layanan, $this->log()[0]->operasi, $this->log()[0]->status, $this->log()[0]->httpStatus, $this->log()[0]->pesan, $this->log()[0]->percobaan,
        ]);
        $this->assertSame([], $this->jeda);
    }

    public function testGetDiRetrySampaiTigaKaliUntuk5xxDanGalatTransport(): void
    {
        $this->transport->antre(new GalatTransport('Connection refused'), new Respons(503, 'sibuk'), new Respons(200, '{}'));

        $r = $this->klien->kirim($this->get());

        $this->assertSame(200, $r->status);
        $this->assertSame([1, 2, 3], array_map(static fn (CatatanIntegrasi $c): int => $c->percobaan, $this->log()));
        $this->assertSame(['koneksi', null, null], array_map(static fn (CatatanIntegrasi $c): ?string => $c->kode, $this->log()));
        $this->assertSame([300, 600], $this->jeda, 'backoff eksponensial (jitter 0)');
        $this->assertSame(0, $this->transport->sisaAntrean());
    }

    public function testGetBerhentiSetelahTigaPercobaanDanMengembalikanResponsTerakhir(): void
    {
        $this->transport->antre(new Respons(500, 'a'), new Respons(502, 'b'), new Respons(500, 'c'), new Respons(200, 'tidak dipakai'));

        $r = $this->klien->kirim($this->get());

        $this->assertSame(500, $r->status);
        $this->assertCount(3, $this->log());
        $this->assertSame(1, $this->transport->sisaAntrean(), 'percobaan ke-4 tidak terjadi');
    }

    public function testPostTidakPernahDiRetry(): void
    {
        $this->transport->antre(new Respons(503, 'sibuk'), new Respons(200, '{}'));

        $r = $this->klien->kirim(new Permintaan('POST', 'https://gateway.contoh/api/sign/pdf', 'esign', 'sign_pdf', json: ['a' => 1]));

        $this->assertSame(503, $r->status);
        $this->assertCount(1, $this->log());
        $this->assertSame([], $this->jeda);
        $this->assertSame(1, $this->transport->sisaAntrean(), 'respons 200 tidak terambil oleh retry');
        $this->transport->kirim(new Permintaan('GET', 'https://x.contoh/', 'uji', 'buang'), new OpsiTransport());

        $this->transport->antre(new GalatTransport('Operation timed out', true));
        $this->expectException(GalatTransport::class);

        try {
            $this->klien->kirim(new Permintaan('POST', 'https://gateway.contoh/api/sign/pdf', 'esign', 'sign_pdf'));
        } finally {
            $this->assertCount(2, $this->log(), 'POST timeout dicatat sekali, tanpa retry');
        }
    }

    public function testTimeoutTercatatSebagaiKegagalanLaluDilempar(): void
    {
        $this->transport->antre(...array_fill(0, 3, new GalatTransport('Operation timed out after 30000 milliseconds', true)));

        try {
            $this->klien->kirim($this->get());
            $this->fail('GalatTransport harus dilempar');
        } catch (GalatTransport $e) {
            $this->assertTrue($e->timeout);
        }

        $this->assertCount(3, $this->log());

        foreach ($this->log() as $c) {
            $this->assertSame([CatatanIntegrasi::GAGAL, null, 'timeout'], [$c->status, $c->httpStatus, $c->kode]);
        }
    }

    public function testLogTanpaNilaiRahasia(): void
    {
        $nik        = '3171999999990001';
        $passphrase = 'Rahasia#Uji-123';
        $body       = json_encode([
            'error'         => "passphrase {$passphrase} salah untuk nik {$nik}",
            'access_token'  => 'eyJhbGciOiJIUzI1NiJ9.sintetis.tandatangan',
            'client_secret' => 'rahasia-klien',
            'detail'        => 'Authorization: Bearer abc.def.ghi',
        ], JSON_UNESCAPED_SLASHES);
        $this->transport->antre(new Respons(401, (string) $body), new GalatTransport("gagal https://gw.contoh/api/sign/pdf?nik={$nik}&passphrase=" . rawurlencode($passphrase)));

        $p = new Permintaan('POST', 'https://gw.contoh/api/sign/pdf', 'esign', 'sign_pdf', query: ['nik' => $nik, 'passphrase' => $passphrase], header: ['Authorization' => 'Basic dXNlcjpwYXNz'], rahasia: [$nik, $passphrase]);
        $this->klien->kirim($p);

        try {
            $this->klien->kirim($p);
        } catch (GalatTransport $e) {
            $this->assertStringNotContainsString($nik, $e->getMessage());
            $this->assertStringNotContainsString($passphrase, $e->getMessage());
        }

        $semua = implode("\n", array_map(static fn (CatatanIntegrasi $c): string => (string) $c->pesan . $c->operasi . $c->layanan, $this->log()));

        foreach ([$nik, $passphrase, rawurlencode($passphrase), 'eyJhbGciOiJIUzI1NiJ9', 'rahasia-klien', 'abc.def.ghi', 'dXNlcjpwYXNz'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $semua, "bocor: {$rahasia}");
        }

        $this->assertStringContainsString('***', $semua);
    }

    public function testPesanGagalDipotongSeribuKarakter(): void
    {
        $this->transport->antre(new Respons(400, str_repeat('x', 5000)));

        $this->klien->kirim(new Permintaan('POST', 'https://gw.contoh/x', 'bkn', 'x'));

        $this->assertSame(1000, mb_strlen((string) $this->log()[0]->pesan));
    }

    public function testOpsiTransportBawaanDanValidasi(): void
    {
        $this->transport->antre(new Respons(200, '{}'));
        $this->klien->kirim($this->get());

        $opsi = $this->transport->tercatat()[0]['opsi'];
        $this->assertSame([5.0, 30.0, true], [$opsi->connectTimeout, $opsi->timeout, $opsi->verifyTls]);

        $this->expectException(InvalidArgumentException::class);
        new OpsiTransport(10.0, 5.0);
    }

    /**
     * @return list<CatatanIntegrasi>
     */
    private function log(): array
    {
        return $this->penampung->isi;
    }

    private function get(): Permintaan
    {
        return new Permintaan('GET', 'https://api.contoh/api_kinerja/referensi/periode', 'bkn', 'periode');
    }
}
