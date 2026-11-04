<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Push\FcmV1Adapter;
use App\Libraries\Push\TopikFcm;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\Integrasi\KunciRsaUji;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-066 (H-04, F6-12) — keanggotaan topik presensi lewat Instance ID `batchAdd|batchRemove` dengan FakeTransport: bentuk
 * permintaan ikut legacy `andr_topic_queue` (`{to: "/topics/…", registration_tokens}`), auth token OAuth service account +
 * `access_token_auth`, potongan 1000 token, galat per token dihitung, HTTP gagal dilempar (retry job), 401 membuang token
 * akses, tanpa token di log.
 *
 * @internal
 */
final class TopikFcmTest extends CIUnitTestCase
{
    private FakeTransport $transport;

    private LogIntegrasiTiruan $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->log       = new LogIntegrasiTiruan();
    }

    public function testBatchRemoveBentukLegacyDanHitungGalatPerToken(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['results' => [[], ['error' => 'NOT_FOUND']]]);

        $this->assertSame(['ok' => 1, 'galat' => 1], $this->topik()->ubah(TopikFcm::BLM_MASUK, false, ['tok-a', 'tok-b']));

        $p = $this->transport->tercatat()[1]['permintaan'];
        $this->assertSame(['POST', TopikFcm::URL_LEPAS], [$p->metode, $p->url]);
        $this->assertSame(['Bearer akses-uji', 'true'], [$p->header['Authorization'], $p->header['access_token_auth']]);
        $this->assertSame(['to' => '/topics/pegawai_blm_masuk', 'registration_tokens' => ['tok-a', 'tok-b']], $p->json);
    }

    public function testBatchAddDipotongPerSeribuToken(): void
    {
        $tokens = array_map(static fn (int $i): string => 'tok-' . $i, range(1, 1001));
        $this->transport->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['results' => array_fill(0, 1000, [])])
            ->antreJson(200, ['results' => [[]]]);

        $this->assertSame(['ok' => 1001, 'galat' => 0], $this->topik()->ubah(TopikFcm::BLM_LKH, true, $tokens));

        $catat = $this->transport->tercatat();
        $this->assertCount(3, $catat, 'token akses di-cache antarpotongan');
        $this->assertSame([TopikFcm::URL_TAMBAH, 1000, 1], [$catat[1]['permintaan']->url, count($catat[1]['permintaan']->json['registration_tokens']), count($catat[2]['permintaan']->json['registration_tokens'])]);
    }

    public function testHttpGagalDilemparDan401MembuangTokenAkses(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-lama', 'expires_in' => 3599])
            ->antreJson(401, ['error' => 'Unauthorized'])
            ->antreJson(200, ['access_token' => 'akses-baru', 'expires_in' => 3599])
            ->antreJson(200, ['results' => [[]]]);
        $topik = $this->topik();

        try {
            $topik->ubah(TopikFcm::BLM_PULANG, true, ['tok-rahasia']);
            $this->fail('HTTP 401 harus dilempar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        $topik->ubah(TopikFcm::BLM_PULANG, true, ['tok-rahasia']);
        $this->assertSame('Bearer akses-baru', $this->transport->tercatat()[3]['permintaan']->header['Authorization']);
        $this->assertCount(4, $this->transport->tercatat(), 'POST tidak di-retry otomatis');
        $this->assertStringNotContainsString('tok-rahasia', $this->log->semuaTeks());
        $this->assertStringNotContainsString('akses-', $this->log->semuaTeks());
    }

    public function testTopikTidakDikenalDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->topik()->ubah('topik_lain', true, ['tok-a']);
    }

    private function topik(): TopikFcm
    {
        $klien = new KlienHttp($this->transport, $this->log, tidur: static function (int $ms): void {
        });
        $fcm = new FcmV1Adapter(
            $klien,
            new CacheService(new MockCache(), 'u_'),
            ['projectId' => 'proyek-uji', 'credentialsPath' => '/di/luar/repo/sa.json'],
            static fn (): string => (string) json_encode(['client_email' => 'sa-uji@proyek-uji.contoh', 'private_key' => KunciRsaUji::pasangan()['privat'], 'token_uri' => 'https://oauth2.googleapis.com/token']),
        );

        return new TopikFcm($klien, $fcm);
    }
}
