<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Esign\BsreEsignAdapter;
use App\Libraries\Esign\LayananEsignMudig;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\EsignMudig;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\LogIntegrasiTiruan;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-065 B4b (H-02, F6-16 / Q6-05 = A) — `services/s_esign/{check_user,sign_pdf}` lewat pipeline route sungguhan: auth
 * `klienLayanan:esign`, amplop [K] `{"status":1|2,"msg","data"}` HTTP 200, unggahan multipart, `esign_hist` wajib per
 * percobaan, PDF ber-TTE base64, NIK langsung ke gateway (tanpa `pegawai.nik`), dan NIK/passphrase tidak bocor ke respons
 * maupun log (AC-02.3). Adapter BSrE nyata + FakeTransport (tanpa jaringan); nilai fiktif.
 *
 * @internal
 */
final class SEsignMudigTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const NIK = '3201010101900077';

    private const PASS = 'frasa-mudig-uji';

    private const NIP = '199001012020011077';

    private const PDF = "%PDF-1.4\nisi dokumen uji\n%%EOF";

    private const PDF_TTE = "%PDF-1.7\nisi dokumen ber-TTE\n%%EOF";

    protected $seed = MasterDataSeeder::class;

    private FakeTransport $transport;

    private LogIntegrasiTiruan $log;

    private string $basic = '';

    /** @var list<string> */
    private array $berkasTmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('cache', new MockCache());
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
        $this->log = new LogIntegrasiTiruan();
        Services::injectMock('integrasiLog', $this->log);
        $this->transport = new FakeTransport();
        $bsre            = new BsreEsignAdapter(
            new KlienHttp($this->transport, $this->log),
            ['driver' => 'bsre', 'baseUrl' => 'https://bsre.contoh', 'username' => 'klien-bsre-uji', 'password' => 'rahasia-bsre-uji', 'tlsVerify' => true, 'timeout' => 20],
            static fn (string $nik): ?string => trim($nik) !== '' ? trim($nik) : null,
        );
        Services::injectMock('esignGatewayNikLangsung', $bsre);

        // Route sementara di test sampai kerangka `Config/RoutesServices.php` (Jalur A) di main; sesudahnya route sungguhan.
        $this->withRoutes([
            ['get', 'services/s_esign/check_user', '\\App\\Controllers\\Services\\SEsignController::checkUser', ['filter' => 'klienLayanan:esign']],
            ['post', 'services/s_esign/sign_pdf', '\\App\\Controllers\\Services\\SEsignController::signPdf', ['filter' => 'klienLayanan:esign']],
        ]);

        $this->klien('mudig', ['esign']);
        $this->buatPegawai(['nip' => self::NIP, 'id_jenis_pegawai' => 1]);
    }

    protected function tearDown(): void
    {
        foreach ($this->berkasTmp as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        service('superglobals')->setFilesArray([]);

        foreach (['cache', 'throttler', 'integrasiLog', 'esignGatewayNikLangsung', 'klienSaatIni', 'request'] as $s) {
            Services::resetSingle($s);
        }

        parent::tearDown();
    }

    public function testCheckUser1111Status1MsgNullDanNikLangsungKeGateway(): void
    {
        $this->transport->antreJson(200, ['status_code' => '1111', 'message' => 'Pengguna ' . self::NIK . ' terdaftar'])
            ->antreJson(200, ['status_code' => '2021', 'message' => 'Pengguna tidak terdaftar']);

        $ok    = $this->badan($this->cekPengguna(self::NIK));
        $tidak = $this->badan($this->cekPengguna(self::NIK));

        // [K] `msg` legacy selalu null (S_esign.php:41 membaca kunci `message` yang tidak ada).
        $this->assertSame(['status' => '1', 'msg' => null, 'data' => []], $ok, 'status STRING ikut S_esign.php');
        $this->assertSame(['status' => '2', 'msg' => null, 'data' => []], $tidak);
        $this->assertSame('https://bsre.contoh/api/user/status/' . self::NIK, $this->transport->tercatat()[0]['permintaan']->url, 'NIK langsung, bukan dari pegawai.nik');
        $this->assertTidakBocor((string) json_encode([$ok, $tidak]));
    }

    public function testCheckUserTanpaNikInvalidParameterTanpaPanggilanGateway(): void
    {
        $res = $this->withHeaders(['Authorization' => $this->basic])->call('get', 'services/s_esign/check_user');

        $res->assertStatus(200);
        $this->assertSame(['status' => '2', 'msg' => 'Invalid parameter.', 'data' => []], $this->badan($res));
        $this->assertSame([], $this->transport->tercatat());
    }

    public function testAuthGagal401DanHakLainDitolak(): void
    {
        $tanpa = $this->call('get', 'services/s_esign/check_user', ['nik' => self::NIK]);
        $tanpa->assertStatus(401);
        $this->assertSame(['status' => 2, 'msg' => 'Authentication failed', 'data' => []], $this->badan($tanpa));

        $this->klien('mudig-stat', ['stat']);
        $res = $this->withHeaders(['Authorization' => $this->basic])->call('post', 'services/s_esign/sign_pdf', $this->param());
        $res->assertStatus(401);

        $this->assertSame([], $this->transport->tercatat());
        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults());
    }

    public function testSignPdfSuksesBase64DanEsignHistStatus1(): void
    {
        $this->transport->antre(new Respons(200, self::PDF_TTE, ['id_dokumen' => 'DOK-1']));

        $res = $this->tandaTangani($this->param(['id_rw' => '12', 'reason' => 'Persetujuan MuDig']));

        $res->assertStatus(200);
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
        $badan = $this->badan($res);
        $this->assertSame(['1', 'Berhasil menandatangani dokumen secara digital.'], [$badan['status'], $badan['msg']]);
        $this->assertSame(self::PDF_TTE, base64_decode($badan['data']['signed_file'], true));

        $p = $this->transport->tercatat()[0]['permintaan'];
        $this->assertSame(['nik' => self::NIK, 'passphrase' => self::PASS, 'tampilan' => 'visible', 'width' => '50', 'height' => '50', 'tag_koordinat' => '##', 'reason' => 'Persetujuan MuDig', 'location' => 'Jakarta', 'image' => 'true'], $p->query);
        $this->assertSame([self::PDF, "\x89PNG\r\n\x1a\n"], [$p->multipart[0]['isi'], substr((string) $p->multipart[1]['isi'], 0, 8)]);
        $this->assertSame(['surat.pdf', '12'], [$p->multipart[0]['namaBerkas'], $p->refId]);

        $hist = $this->db->table('esign_hist')->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame(
            [self::NIP, self::NIK, 'Pejabat Uji', 'Mudig Uji', '12', 'arsip/surat-uji.pdf', '1', '200', '10.0.0.5', 'Peramban Uji', null],
            [$hist[0]['nip'], $hist[0]['nik'], $hist[0]['nama_lengkap'], $hist[0]['modul'], $hist[0]['id_rw'], $hist[0]['file_pdf_to_sign'], $hist[0]['status'], $hist[0]['status_code'], $hist[0]['ip_address'], $hist[0]['user_agent'], $hist[0]['keterangan']],
        );
        $this->assertTidakBocor((string) json_encode($badan));
    }

    public function testSignPdfGagalGatewayEsignHistStatus2NipTakDikenalNullTanpaRahasia(): void
    {
        $this->transport->antreJson(400, ['status_code' => '2031', 'error' => 'Passphrase ' . self::PASS . ' salah untuk nik ' . self::NIK])
            ->antre(new GalatTransport('Operation timed out', true));

        // Tanpa nama_lengkap/modul tetap diproses (legacy lolos, U3): NIP tak dikenal → nama '' dan modul bawaan 'MuDig'.
        $gagal   = $this->badan($this->tandaTangani($this->param(['nip' => '199912312099121999', 'file_pdf_to_sign' => '', 'nama_lengkap' => '', 'modul' => ''])));
        $timeout = $this->badan($this->tandaTangani($this->param()));

        $this->assertSame('2', $gagal['status']);
        $this->assertSame([], $gagal['data']);
        $this->assertSame('2', $timeout['status']);
        $this->assertCount(2, $this->transport->tercatat(), 'POST tidak di-retry (BP-3)');

        $hist = $this->db->table('esign_hist')->orderBy('id_esign_hist')->get()->getResultArray();
        $this->assertSame([[null, '2', '2031', 'surat.pdf', '', 'MuDig'], [self::NIP, '2', 'timeout', 'arsip/surat-uji.pdf', 'Pejabat Uji', 'Mudig Uji']], array_map(static fn (array $h): array => [$h['nip'], $h['status'], $h['status_code'], $h['file_pdf_to_sign'], $h['nama_lengkap'], $h['modul']], $hist));
        $this->assertSame($gagal['msg'], $hist[0]['keterangan']);

        // NIP dikenal + nama_lengkap kosong → nama bergelar dari pegawai.
        $this->db->table('pegawai')->where('nip', self::NIP)->update(['glr_awal' => 'Dr.', 'nama' => 'Pegawai Uji', 'glr_akhir' => 'M.Si.']);
        $this->transport->antreJson(400, ['status_code' => '2031', 'error' => 'gagal']);
        $this->tandaTangani($this->param(['nama_lengkap' => '']));
        $this->assertSame('Dr. Pegawai Uji, M.Si.', $this->db->table('esign_hist')->orderBy('id_esign_hist', 'DESC')->get(1)->getRow()->nama_lengkap);
        $this->assertTidakBocor((string) json_encode([$gagal, $timeout]) . $hist[0]['keterangan'] . $hist[1]['keterangan']);
    }

    public function testParameterTidakLengkapAtauBerkasTidakSahInvalidParameterTanpaGatewayDanTanpaHist(): void
    {
        $kasus = [
            'tanpa passphrase' => [$this->param(['passphrase' => '']), true, true],
            'tanpa nik'        => [$this->param(['nik' => '']), true, true],
            'tanpa file'       => [$this->param(), false, true],
            'tanpa imageTTD'   => [$this->param(), true, false],
        ];

        foreach ($kasus as $nama => [$param, $pdf, $gambar]) {
            $badan = $this->badan($this->tandaTangani($param, $pdf ? self::PDF : null, $gambar ? $this->png() : null));
            $this->assertSame(['status' => '2', 'msg' => LayananEsignMudig::PESAN_PARAMETER, 'data' => []], $badan, $nama);
        }

        $bukanPdf = $this->badan($this->tandaTangani($this->param(), 'bukan pdf', $this->png()));
        $this->assertSame('Invalid parameter.', $bukanPdf['msg'], 'file bukan PDF');
        $bukanGambar = $this->badan($this->tandaTangani($this->param(), self::PDF, 'GIF89a bukan png'));
        $this->assertSame('Invalid parameter.', $bukanGambar['msg'], 'imageTTD bukan JPEG/PNG');

        // Batas unggahan dari Config\EsignMudig (U5), bukan hard-code.
        $cfg   = config(EsignMudig::class);
        $batas = [$cfg->pdfMaks, $cfg->gambarMaks];

        try {
            $cfg->pdfMaks = strlen(self::PDF) - 1;
            $this->assertSame(['2', 'Invalid parameter.'], array_values(array_slice($this->badan($this->tandaTangani($this->param())), 0, 2)), 'PDF lewat batas');
            $cfg->pdfMaks    = $batas[0];
            $cfg->gambarMaks = strlen($this->png()) - 1;
            $this->assertSame(['2', 'Invalid parameter.'], array_values(array_slice($this->badan($this->tandaTangani($this->param())), 0, 2)), 'gambar lewat batas');
        } finally {
            [$cfg->pdfMaks, $cfg->gambarMaks] = $batas;
        }

        $this->assertSame([], $this->transport->tercatat());
        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults());
    }

    /**
     * @param list<string> $hak
     */
    private function klien(string $username, array $hak): void
    {
        $r           = (new PenyimpanKlien($this->db))->buat('MuDig Uji', $username, 3, $hak, null);
        $this->basic = 'Basic ' . base64_encode($username . ':' . $r['rahasia']);
    }

    private function cekPengguna(string $nik): TestResponse
    {
        $res = $this->withHeaders(['Authorization' => $this->basic])->call('get', 'services/s_esign/check_user', ['nik' => $nik]);
        $res->assertStatus(200);

        return $res;
    }

    /**
     * @param array<string, string> $ubah
     *
     * @return array<string, string>
     */
    private function param(array $ubah = []): array
    {
        return $ubah + [
            'nik'              => self::NIK,
            'passphrase'       => self::PASS,
            'nama_lengkap'     => 'Pejabat Uji',
            'modul'            => 'Mudig Uji',
            'file_pdf_to_sign' => 'arsip/surat-uji.pdf',
            'nip'              => self::NIP,
            'ip_address'       => '10.0.0.5',
            'user_agent'       => 'Peramban Uji',
        ];
    }

    /**
     * @param array<string, string> $param
     */
    private function tandaTangani(array $param, ?string $pdf = self::PDF, ?string $gambar = 'png'): TestResponse
    {
        $berkas = [];

        if ($pdf !== null) {
            $berkas['file'] = $this->unggahan('surat.pdf', 'application/pdf', $pdf);
        }

        if ($gambar !== null) {
            $berkas['imageTTD'] = $this->unggahan('ttd.png', 'image/png', $gambar === 'png' ? $this->png() : $gambar);
        }

        // FileCollection membaca $_FILES lewat service superglobals (pola LampiranEndpointTest).
        service('superglobals')->setFilesArray($berkas);

        try {
            return $this->withHeaders(['Authorization' => $this->basic])->call('post', 'services/s_esign/sign_pdf', $param);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function unggahan(string $nama, string $tipe, string $isi): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'sesign');
        file_put_contents($tmp, $isi);
        $this->berkasTmp[] = $tmp;

        return ['name' => $nama, 'type' => $tipe, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($isi)];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function badan(TestResponse $res): array
    {
        return (array) json_decode((string) $res->getJSON(), true);
    }

    private function assertTidakBocor(string $teks): void
    {
        foreach ([self::PASS, self::NIK, 'rahasia-bsre-uji'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $teks . $this->log->semuaTeks(), 'AC-02.3 bocor: ' . $rahasia);
        }
    }
}
