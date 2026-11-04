<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\NotFoundException;
use App\Interfaces\Layanan\TokenSuratCutiInterface;
use App\Libraries\Layanan\Cuti\SuratCutiService;
use App\Libraries\Layanan\Cuti\VerifikasiSuratCutiService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-065 langkah 3 — route publik surat cuti ber-token (PRD Fase 6 v1.0f §7.2) dengan repo token PALSU (lepas dari
 * tabel `surat_cuti_token`; token nyata DBV-030 diuji {@see TokenSuratCutiTest}):
 * - AC-02.8 (Q6-23): verifikasi data minimal tanpa NIP/unduh PDF; status "belum" sebelum TTE, "ditandatangani" + nama &
 *   tanggal WIB sesudah TTE; token salah → 404 seragam;
 * - AC-02.7 (Q6-22): PDF ber-TTE `inline`; token salah/nonaktif/surat tanpa TTE → 404 seragam; throttle; `no-store` +
 *   `noindex`; tidak ada berkas lain (hanya surat TTE cuti itu).
 *
 * @internal
 */
final class SuratCutiPublikTest extends DatabaseTestCase
{
    use EsignUjiTrait;
    use FeatureTestTrait;

    protected $seed = MasterDataSeeder::class;

    /** @var array<string, int> token → id cuti (repo palsu, token aktif) */
    private array $aktif = [];

    protected function setUp(): void
    {
        parent::setUp();
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        Services::reset(true);
        parent::tearDown();
    }

    public function testVerifikasiSebelumDanSesudahTteTanpaNip(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $token                   = $this->token($id);
        $svc                     = $this->layanan();

        $belum = $svc->verifikasi($token);
        $this->assertSame(['asli', 'Surat Cuti', 'Pejabat Uji', null, 'belum'], [$belum['status_keaslian'], $belum['jenis_surat'], $belum['penanda_tangan'], $belum['tanggal_tanda_tangan'], $belum['status_tte']]);

        $this->esignService()->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        $sudah = $svc->verifikasi($token);

        $this->assertSame('ditandatangani', $sudah['status_tte']);
        $this->assertNotNull($sudah['penanda_tangan']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} WIB$/', (string) $sudah['tanggal_tanda_tangan']);

        $json = (string) json_encode($sudah);
        $this->assertStringNotContainsString($a['pemohon']['nip'], $json, 'tanpa NIP pemohon');
        $this->assertStringNotContainsString($a['pyb']['nip'], $json, 'tanpa NIP penanda tangan');
        $this->assertSame(['status_keaslian', 'jenis_surat', 'jenis_cuti', 'penanda_tangan', 'tanggal_tanda_tangan', 'status_tte'], array_keys($sudah), 'tanpa tautan unduh / id');
    }

    public function testPdfHanyaSuratTteDan404SeragamUntukSemuaAlasan(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $token                   = $this->token($id);
        $svc                     = $this->layanan();

        $this->assertSame(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, $this->pesan404(static fn () => $svc->pdfTte($token)), 'surat tanpa TTE');

        $this->esignService()->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        $pdf = $svc->pdfTte($token);
        $this->assertStringStartsWith('%PDF', $pdf['isi']);
        $this->assertSame(VerifikasiSuratCutiService::NAMA_BERKAS, $pdf['nama'], 'S6: nama berkas generik tanpa id cuti / NIP');

        foreach ([str_repeat('a', 64), strtoupper($token), substr($token, 1), $token . ' ', 'bukan-token'] as $salah) {
            $this->assertSame(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, $this->pesan404(static fn () => $svc->pdfTte($salah)));
            $this->assertSame(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, $this->pesan404(static fn () => $svc->verifikasi($salah)));
        }

        // Token nonaktif (repo tidak mengembalikan id) dan cuti tidak final → 404 seragam.
        unset($this->aktif[$token]);
        $this->assertSame(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, $this->pesan404(static fn () => $svc->pdfTte($token)));
        $token2 = $this->token($id);
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['status' => 2]);
        $this->assertSame(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, $this->pesan404(static fn () => $svc->verifikasi($token2)));
    }

    public function testRutePublikTanpaJwt404SeragamHeaderDanThrottle(): void
    {
        $token = str_repeat('ab', 32);

        foreach (["api/v1/publik/surat-cuti/{$token}", "api/v1/publik/surat-cuti/{$token}/pdf"] as $url) {
            $res = $this->panggil($url);
            $res->assertStatus(404);
            $this->assertSame('noindex, nofollow', $res->response()->getHeaderLine('X-Robots-Tag'));
            $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString(VerifikasiSuratCutiService::PESAN_TIDAK_DITEMUKAN, (string) $res->response()->getBody());
        }

        for ($i = 3; $i <= 30; $i++) {
            $this->panggil("api/v1/publik/surat-cuti/{$token}")->assertStatus(404);
        }

        $this->panggil("api/v1/publik/surat-cuti/{$token}/pdf")->assertStatus(429);
    }

    private function layanan(): VerifikasiSuratCutiService
    {
        $this->cutiService(); // menyiapkan lampiran palsu bersama ($this->lampiranUji)
        $aktif = &$this->aktif;

        return new VerifikasiSuratCutiService(new class ($aktif) implements TokenSuratCutiInterface {
            /** @param array<string, int> $aktif */
            public function __construct(private array &$aktif)
            {
            }

            public function idCutiAktif(string $tokenMentah): ?int
            {
                return $this->aktif[$tokenMentah] ?? null;
            }
        }, new SuratCutiService($this->db, $this->lampiranUji), $this->db);
    }

    private function token(int $id): string
    {
        $token               = bin2hex(random_bytes(32));
        $this->aktif[$token] = $id;

        return $token;
    }

    private function pesan404(callable $aksi): string
    {
        try {
            $aksi();
        } catch (NotFoundException $e) {
            return $e->getMessage();
        }

        $this->fail('harus 404');
    }

    private function panggil(string $url): \CodeIgniter\Test\TestResponse
    {
        service('superglobals')->setServer('REMOTE_ADDR', '198.51.100.90');

        try {
            return $this->withHeaders([])->get($url);
        } finally {
            Services::resetSingle('response');
        }
    }
}
