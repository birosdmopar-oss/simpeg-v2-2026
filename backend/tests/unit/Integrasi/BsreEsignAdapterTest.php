<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Esign\BsreEsignAdapter;
use App\Libraries\Integrasi\Http\CurlTransport;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Redaktor;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Integrations;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-065 (H-02, F6-17) — adapter BSrE real dengan FakeTransport (tanpa jaringan): bentuk permintaan ikut legacy
 * (`GET /api/user/status/{nik}`, `POST /api/sign/pdf?nik&passphrase&…` multipart, Basic), `1111` = bersertifikat, sukses
 * hanya bila badan PDF (pengaman F6-17), gateway mati/timeout tidak senyap (AC-02.2), dan NIK/passphrase/kredensial tidak
 * ada di log, pesan, maupun hasil (AC-02.3). Nilai fiktif.
 *
 * @internal
 */
final class BsreEsignAdapterTest extends CIUnitTestCase
{
    private const NIK = '3201010101900001';

    private const PASS = 'frasa-rahasia-uji';

    private const KONFIG = [
        'driver'    => 'bsre',
        'baseUrl'   => 'https://bsre.contoh/',
        'username'  => 'klien-bsre-uji',
        'password'  => 'rahasia-bsre-uji',
        'tlsVerify' => true,
        'timeout'   => 20,
    ];

    private FakeTransport $transport;

    private LogIntegrasiTiruan $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->log       = new LogIntegrasiTiruan();
    }

    public function testKonfigurasiKosongAtauTidakSahDitolakSaatDibuat(): void
    {
        foreach ([[['baseUrl' => ''], 'baseUrl'], [['tlsVerify' => ''], 'tlsVerify'], [['tlsVerify' => 'tidak'], 'tlsVerify'], [['timeout' => 0], 'timeout'], [['caBundle' => '/tidak/ada/ca.pem'], 'caBundle']] as [$ubah, $kunci]) {
            try {
                $this->bsre($ubah);
                $this->fail('harus ditolak: ' . $kunci);
            } catch (KonfigurasiIntegrasiTidakLengkap $e) {
                $this->assertSame([$kunci], $e->kunci);
                $this->assertStringNotContainsString('rahasia-bsre-uji', $e->getMessage());
            }
        }

        $this->assertSame([], $this->transport->tercatat());
    }

    public function testTlsBsreIkutLegacyBolehFalseDariEnvDanCaBundleDiteruskan(): void
    {
        // Keputusan Horii 03-11-2026 (kartu Catatan Teknis): esign boleh tlsVerify=false; BKN tetap wajib true (TokenBknTest).
        $this->transport->antreJson(200, ['status_code' => '1111'])->antreJson(200, ['status_code' => '1111']);

        $mati = $this->bsre(['tlsVerify' => 'false']);
        $this->assertFalse($mati->tlsDiverifikasi());
        $mati->checkUser('199001012020011001');
        $this->assertFalse($this->transport->tercatat()[0]['opsi']->verifyTls);
        $this->assertSame(false, CurlTransport::opsiKlien($this->transport->tercatat()[0]['opsi'])['verify'], 'peer & host tidak diverifikasi');

        $ca = (string) tempnam(sys_get_temp_dir(), 'ca');
        file_put_contents($ca, '-----BEGIN CERTIFICATE-----
uji
-----END CERTIFICATE-----
');

        try {
            $ketat = $this->bsre(['caBundle' => $ca]);
            $this->assertTrue($ketat->tlsDiverifikasi());
            $ketat->checkUser('199001012020011001');
            $opsi = CurlTransport::opsiKlien($this->transport->tercatat()[1]['opsi']);
            $this->assertSame([$ca, false], [$opsi['verify'], $opsi['allow_redirects']], 'caBundle → CAINFO, redirect tidak diikuti');
        } finally {
            unlink($ca);
        }
    }

    public function testBawaanRepoTlsTrue(): void
    {
        $this->assertTrue(config(Integrations::class)->esign['tlsVerify']);
        $this->assertSame('', config(Integrations::class)->esign['caBundle']);
        $this->assertSame(['verify' => true, 'allow_redirects' => false], array_intersect_key(CurlTransport::opsiKlien(new \App\Libraries\Integrasi\Http\OpsiTransport()), ['verify' => 1, 'allow_redirects' => 1]));
    }

    public function testUjiKoneksiNikFiktifDanTolakKredensial(): void
    {
        $this->transport->antreJson(200, ['status_code' => '2021', 'message' => 'NIK tidak terdaftar'])->antreJson(401, ['error' => 'Unauthorized']);
        $bsre = $this->bsre();

        $this->assertSame(['ok' => true, 'http' => '200'], array_intersect_key($bsre->ujiKoneksi(), ['ok' => 1, 'http' => 1]));
        $this->assertSame(['ok' => false, 'http' => '401'], array_intersect_key($bsre->ujiKoneksi(), ['ok' => 1, 'http' => 1]));
        $this->assertStringEndsWith('/api/user/status/0000000000000000', $this->transport->tercatat()[0]['permintaan']->url);
    }

    public function testCekStatus1111BersertifikatDanBentukPermintaan(): void
    {
        $this->transport->antreJson(200, ['status_code' => '1111', 'message' => 'Sertifikat aktif untuk ' . self::NIK]);

        $r = $this->bsre()->checkUser('199001012020011001');

        $this->assertSame(['200', true], [$r['status_code'], $r['has_certificate']]);
        $this->assertStringNotContainsString(self::NIK, $r['message'], 'NIK dari pesan gateway disensor');
        $p = $this->transport->tercatat()[0];
        $this->assertSame(['GET', 'https://bsre.contoh/api/user/status/' . self::NIK], [$p['permintaan']->metode, $p['permintaan']->url]);
        $this->assertSame('Basic ' . base64_encode('klien-bsre-uji:rahasia-bsre-uji'), $p['permintaan']->header['Authorization']);
        $this->assertSame([5.0, 20.0, true], [$p['opsi']->connectTimeout, $p['opsi']->timeout, $p['opsi']->verifyTls]);
    }

    public function testCekStatusTidakBersertifikatNikKosongDanTimeout(): void
    {
        $this->transport->antreJson(200, ['status_code' => '2021', 'message' => 'Belum terdaftar'])
            ->antre(new GalatTransport('Operation timed out', true), new GalatTransport('Operation timed out', true), new GalatTransport('Operation timed out', true));
        $bsre = $this->bsre();

        $this->assertSame(['2021', false], array_values(array_intersect_key($bsre->checkUser('199001012020011001'), ['status_code' => 1, 'has_certificate' => 1])));
        $this->assertSame('timeout', $bsre->checkUser('199001012020011001')['status_code'], 'AC-02.2 gateway mati tidak senyap');

        $tanpaNik = $this->bsre([], static fn (): ?string => null)->checkUser('199001012020011002');
        $this->assertSame('404', $tanpaNik['status_code']);
        $this->assertCount(4, $this->transport->tercatat(), 'NIK kosong tanpa panggilan keluar');
    }

    public function testSignPdfSuksesHanyaBilaPdfDanQueryIkutLegacy(): void
    {
        $this->transport->antre(new Respons(200, "%PDF-1.7\nisi-ber-tte", ['id_dokumen' => 'DOK-UJI-1']));

        $r = $this->bsre()->signPdf('%PDF-1.4 asli', '199001012020011001', self::PASS, ['reason' => 'Persetujuan cuti', 'id_rw' => 7]);

        $this->assertSame(['200', "%PDF-1.7\nisi-ber-tte", '2026-11-03 10:00:00', 'DOK-UJI-1'], [$r['status_code'], $r['signed_pdf'], $r['signed_at'], $r['signature_id']]);
        $p = $this->transport->tercatat()[0]['permintaan'];
        $this->assertSame(['POST', 'https://bsre.contoh/api/sign/pdf', '7'], [$p->metode, $p->url, $p->refId]);
        $this->assertSame([
            'nik'           => self::NIK, 'passphrase' => self::PASS, 'tampilan' => 'visible', 'width' => '50', 'height' => '50',
            'tag_koordinat' => '##', 'reason' => 'Persetujuan cuti', 'location' => 'Jakarta', 'image' => 'false',
        ], $p->query);
        $this->assertSame(['file'], array_column((array) $p->multipart, 'nama'));
        $this->assertContains(self::PASS, $p->rahasia);
        $this->assertContains(self::NIK, $p->rahasia);
    }

    public function testJsonNonErrorBukanPdfDianggapGagal(): void
    {
        // Legacy menulis JSON non-error ini sebagai berkas PDF (bsre_esign.php:102-126) — pengaman F6-17.
        $this->transport->antreJson(200, ['status' => 'diproses']);

        $r = $this->bsre()->signPdf('%PDF-1.4', '199001012020011001', self::PASS);

        $this->assertSame(['502', null], [$r['status_code'], $r['signed_pdf']]);
    }

    public function testGagalGatewayTanpaRahasiaDiPesanDanLogTanpaRetry(): void
    {
        $this->transport->antreJson(400, ['status_code' => '2031', 'error' => 'Passphrase ' . self::PASS . ' salah untuk nik ' . self::NIK])
            ->antre(new GalatTransport('Operation timed out', true));
        $bsre = $this->bsre();

        $r = $bsre->signPdf('%PDF-1.4', '199001012020011001', self::PASS, ['imageTTD' => "\x89PNG gambar"]);
        $this->assertSame('2031', $r['status_code']);
        $t = $bsre->signPdf('%PDF-1.4', '199001012020011001', self::PASS);
        $this->assertSame('timeout', $t['status_code']);

        $this->assertCount(2, $this->transport->tercatat(), 'POST tidak di-retry (BP-3)');
        $this->assertSame(['file', 'imageTTD'], array_column((array) $this->transport->tercatat()[0]['permintaan']->multipart, 'nama'));
        $this->assertSame('true', $this->transport->tercatat()[0]['permintaan']->query['image']);

        $semua = $r['message'] . $t['message'] . $this->log->semuaTeks();

        foreach ([self::PASS, self::NIK, 'rahasia-bsre-uji', base64_encode('klien-bsre-uji:rahasia-bsre-uji')] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $semua, 'AC-02.3 bocor: ' . $rahasia);
        }
    }

    public function testRedaksiUrlBerQueryDanPathNik(): void
    {
        $url = 'https://bsre.contoh/api/sign/pdf?nik=' . self::NIK . '&passphrase=' . rawurlencode('frasa rahasia/uji') . '&tampilan=visible';

        $teks = (new Redaktor())->teks("gagal memanggil {$url}; GET https://bsre.contoh/api/user/status/" . self::NIK, [self::NIK, 'frasa rahasia/uji']);

        $this->assertStringNotContainsString(self::NIK, $teks);
        $this->assertStringNotContainsString('frasa', $teks);
        $this->assertStringContainsString('tampilan=visible', $teks, 'parameter lain tetap terbaca');
    }

    public function testParameterRahasiaBertandaSensitive(): void
    {
        $p = (new \ReflectionMethod(BsreEsignAdapter::class, 'signPdf'))->getParameters()[2];

        $this->assertSame('passphrase', $p->getName());
        $this->assertNotSame([], $p->getAttributes(\SensitiveParameter::class));
    }

    public function testDriverBsreDiServicesGagalSaatDibuatBilaKonfigurasiKosong(): void
    {
        $esign                             = config(Integrations::class)->esign;
        config(Integrations::class)->esign = ['driver' => 'bsre', 'baseUrl' => '', 'username' => '', 'password' => '', 'tlsVerify' => true, 'caBundle' => '', 'qrLogo' => '', 'timeout' => 30.0];

        try {
            $this->expectException(KonfigurasiIntegrasiTidakLengkap::class);
            \Config\Services::esignGateway(false);
        } finally {
            config(Integrations::class)->esign = $esign;
        }
    }

    /**
     * @param array<string, mixed>          $ubah
     * @param (\Closure(string): ?string)|null $nik
     */
    private function bsre(array $ubah = [], ?\Closure $nik = null): BsreEsignAdapter
    {
        return new BsreEsignAdapter(
            new KlienHttp($this->transport, $this->log, tidur: static function (int $ms): void {
            }),
            $ubah + self::KONFIG,
            $nik ?? static fn (string $nip): string => self::NIK,
            static fn (): string => '2026-11-03 10:00:00',
        );
    }
}
