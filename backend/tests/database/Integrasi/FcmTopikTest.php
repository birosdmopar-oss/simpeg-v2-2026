<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use App\Jobs\FcmTopikJob;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Push\AntreTopikFcm;
use App\Libraries\Push\FcmV1Adapter;
use App\Libraries\Push\TokenFcmService;
use App\Libraries\Push\TopikFcm;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\KunciRsaUji;
use Tests\Support\Integrasi\LogIntegrasiTiruan;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * MAKE-066 (H-04, F6-12) — topik presensi FCM ikut legacy: aturan produsen masuk/pulang/LKH [K `L:1834-1878`,
 * `L:1976-2044`, `L_lkh.php:430-445`], hanya diantre bila driver `fcm`, kegagalan mengantre tidak menggagalkan presensi
 * (AC-04.3), dan job `fcm-topik` hanya memakai token Android (`platform` = 2) milik NIP itu.
 *
 * @internal
 */
final class FcmTopikTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    /** @var array<string, mixed> */
    private array $pushAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushAsli = config(\Config\Integrations::class)->push;
    }

    protected function tearDown(): void
    {
        config(\Config\Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        parent::tearDown();
    }

    public function testAturanProdusenIkutLegacy(): void
    {
        $nip   = $this->buatPegawai();
        $antre = $this->produsen();

        $antre->presensi($this->db, $nip, '2026-10-27', true);
        $antre->presensi($this->db, $nip, '2026-10-27', false);
        $this->db->table('riwayat_lckh')->insert(['nip' => $nip, 'tgl_laporan' => '2026-10-27', 'nama' => 'Pegawai Uji', 'nama_atasan' => 'Atasan Uji', 'kegiatan' => '["Uji"]', 'output' => '["Dokumen"]', 'status' => 0]);
        $antre->presensi($this->db, $nip, '2026-10-27', false);
        $antre->lkhDibuat($nip);

        $this->assertSame([
            [[TopikFcm::BLM_MASUK], [TopikFcm::BLM_PULANG]],
            [[TopikFcm::BLM_PULANG], [TopikFcm::BLM_MASUK, TopikFcm::BLM_LKH]],
            [[TopikFcm::BLM_PULANG, TopikFcm::BLM_LKH], [TopikFcm::BLM_MASUK]],
            [[TopikFcm::BLM_LKH], []],
        ], array_map(static fn (array $j): array => [$j[1]['lepas'], $j[1]['tambah']], $this->antrean()));
        $this->assertSame([['fcm-topik', $nip]], array_values(array_unique(array_map(static fn (array $j): array => [$j[0], $j[1]['nip']], $this->antrean()), SORT_REGULAR)));
    }

    public function testDriverMockTidakMengantreDanGagalAntreDitelan(): void
    {
        $nip = $this->buatPegawai();
        $this->produsen('mock')->presensi($this->db, $nip, '2026-10-27', true);
        $this->assertSame([], $this->antrean());

        (new AntreTopikFcm(static fn (): QueueInterface => throw new RuntimeException('antrean mati'), 'fcm'))->presensi($this->db, $nip, '2026-10-27', true);
        $this->addToAssertionCount(1);
    }

    public function testPresensiMasukMengantreSesudahCommitDanTetapSuksesBilaAntreanGagal(): void
    {
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
        $nip                                      = $this->pegawaiPresensi()['nip'];

        [$status] = $this->online('2026-10-27 07:00:00')->masuk($this->authPegawai($nip), []);
        $this->assertSame(201, $status);
        $this->assertSame([['fcm-topik', ['nip' => $nip, 'tambah' => [TopikFcm::BLM_PULANG], 'lepas' => [TopikFcm::BLM_MASUK]]]], $this->antrean());

        Services::injectMock('queue', new class () {
            public function push(): never
            {
                throw new RuntimeException('antrean mati');
            }
        });
        [$status] = $this->online('2026-10-27 16:00:00')->pulang($this->authPegawai($nip), []);
        $this->assertSame(200, $status, 'AC-04.3: gagal antre tidak menggagalkan presensi');
        $this->assertSame('16:00:00', $this->barisDh($nip, '2026-10-27')['jam_pulang']);
    }

    public function testJobHanyaTokenAndroidMilikNip(): void
    {
        $nip  = $this->buatPegawai();
        $lain = $this->buatPegawai();
        $this->token($nip, 'tok-android-1', 2, '2026-10-01 00:00:00');
        $this->token($nip, 'tok-android-2', 2, '2026-10-02 00:00:00');
        $this->token($nip, 'tok-web', 1, '2026-10-03 00:00:00', $this->buatAkunTanpaNip('pegawai.uji.topik', Role::PEGAWAI, ['nip' => $nip])['id_pengguna']);
        $this->token($lain, 'tok-android-lain', 2, '2026-10-03 00:00:00');

        $transport = (new FakeTransport())
            ->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['results' => [[], []]])
            ->antreJson(200, ['results' => [[], []]]);
        $this->pasangFcm($transport);

        $hasil = (new FcmTopikJob(['nip' => $nip, 'tambah' => [TopikFcm::BLM_PULANG], 'lepas' => [TopikFcm::BLM_MASUK, 'topik_asing']]))->process();

        $this->assertSame(FcmTopikJob::HASIL_SELESAI . ' ok=4 galat=0', $hasil);
        $catat = array_map(static fn (array $c): array => [$c['permintaan']->url, $c['permintaan']->json['to'] ?? null, $c['permintaan']->json['registration_tokens'] ?? null], array_slice($transport->tercatat(), 1));
        $this->assertSame([
            [TopikFcm::URL_LEPAS, '/topics/pegawai_blm_masuk', ['tok-android-2', 'tok-android-1']],
            [TopikFcm::URL_TAMBAH, '/topics/pegawai_blm_pulang', ['tok-android-2', 'tok-android-1']],
        ], $catat);
    }

    public function testGalatPerTokenTercatatDanMasukHasilJob(): void
    {
        // CR-099 (B1, BP-7): batchAdd HTTP 200 dengan results[].error tidak boleh hilang tanpa jejak.
        $nip = $this->buatPegawai();
        $this->token($nip, 'tok-android-1', 2, '2026-10-01 00:00:00');
        $this->token($nip, 'tok-android-2', 2, '2026-10-02 00:00:00');
        $transport = (new FakeTransport())
            ->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['results' => [['error' => 'NOT_FOUND'], ['error' => 'INVALID_ARGUMENT']]]);
        $this->pasangFcm($transport);

        $hasil = (new FcmTopikJob(['nip' => $nip, 'tambah' => [TopikFcm::BLM_LKH], 'lepas' => []]))->process();

        $this->assertSame(FcmTopikJob::HASIL_SELESAI . ' ok=0 galat=2', $hasil);
        $this->assertLogContains('warning', '[fcm-topik] tambah topik pegawai_blm_lkh: 0 token ok, 2 token ditolak FCM.');
    }

    public function testJobTanpaTokenAndroidSelesaiTanpaHttp(): void
    {
        $nip = $this->buatPegawai();
        $this->token($nip, 'tok-web', 1, '2026-10-03 00:00:00', $this->buatAkunTanpaNip('pegawai.uji.topik2', Role::PEGAWAI, ['nip' => $nip])['id_pengguna']);
        $transport = new FakeTransport();
        $this->pasangFcm($transport);

        $this->assertSame(FcmTopikJob::HASIL_TANPA_TOKEN, (new FcmTopikJob(['nip' => $nip, 'tambah' => [], 'lepas' => [TopikFcm::BLM_LKH]]))->process());
        $this->assertSame([], $transport->tercatat());
    }

    private function produsen(string $driver = 'fcm'): AntreTopikFcm
    {
        return new AntreTopikFcm(null, $driver);
    }

    /**
     * Job di `queue_jobs` antrean `push`: [nama job, data].
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function antrean(): array
    {
        return array_map(static function (array $r): array {
            $p = (array) json_decode((string) $r['payload'], true);

            return [(string) $p['job'], (array) $p['data']];
        }, $this->db->table('queue_jobs')->where('queue', 'push')->orderBy('id', 'ASC')->get()->getResultArray());
    }

    private function token(string $nip, string $token, int $platform, string $dibuat, int|string|null $idPengguna = null): void
    {
        $this->db->table('firebase_token')->insert(['nip' => $platform === TokenFcmService::PLATFORM_WEB ? null : $nip, 'id_pengguna' => $idPengguna, 'platform' => $platform, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => $dibuat]);
    }

    private function pasangFcm(FakeTransport $transport): void
    {
        $klien = new KlienHttp($transport, new LogIntegrasiTiruan(), tidur: static function (int $ms): void {
        });
        Services::injectMock('klienHttp', $klien);
        Services::injectMock('pushNotifGateway', new FcmV1Adapter(
            $klien,
            new CacheService(new MockCache(), 'u_'),
            ['projectId' => 'proyek-uji', 'credentialsPath' => '/di/luar/repo/sa.json'],
            static fn (): string => (string) json_encode(['client_email' => 'sa-uji@proyek-uji.contoh', 'private_key' => KunciRsaUji::pasangan()['privat'], 'token_uri' => 'https://oauth2.googleapis.com/token']),
        ));
    }
}
