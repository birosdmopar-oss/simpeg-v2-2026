<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Cuti\KunciSuratCuti;
use App\Libraries\Layanan\Cuti\SuratCutiService;
use App\Libraries\Layanan\Cuti\TokenSuratCutiService;
use App\Libraries\Layanan\Cuti\VerifikasiSuratCutiService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Encryption;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-065 langkah 3 — token publik surat cuti NYATA (tabel `surat_cuti_token`, DBV-030; PRD Fase 6 v1.0f Q6-22/Q6-23):
 * terbit idempoten (satu per cuti) dengan hash + salinan terenkripsi (token mentah tidak tersimpan), QR kaki = URL
 * verifikasi tanpa NIP (AC-02.8) dan token yang sama di URL PDF QR tanda tangan (AC-02.7), nonaktif/aktif/rotasi tercatat
 * `audit_logs` dengan rotasi hanya sebelum TTE (DBV-030 Q3), hapus cuti menghapus token di transaksi yang sama → 404 (Q2),
 * serta alur publik ujung-ke-ujung lewat HTTP.
 *
 * @internal
 */
final class TokenSuratCutiTest extends DatabaseTestCase
{
    use EsignUjiTrait;
    use FeatureTestTrait;

    private const KUNCI = 'phpunit-only-encryption-key-surat-cuti-0123456789ab';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(Encryption::class)->key = self::KUNCI;
        Services::resetSingle('encrypter');
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
    }

    protected function tearDown(): void
    {
        config(Encryption::class)->key = (new Encryption())->key;
        Services::resetSingle('encrypter');
        Services::resetSingle('throttler');
        Services::reset(true);
        parent::tearDown();
    }

    public function testTerbitIdempotenHashDanEnkripsiTanpaTokenMentah(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $token        = $this->token();

        $mentah = (string) $token->untukCuti($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $mentah);
        $this->assertSame($mentah, $token->untukCuti($id), 'satu token per cuti');
        $this->assertSame(1, $this->db->table('surat_cuti_token')->where('id_riwayat_cuti', $id)->countAllResults());

        $baris = $this->db->table('surat_cuti_token')->where('id_riwayat_cuti', $id)->get()->getRowArray();
        $this->assertSame(hash('sha256', $mentah), $baris['token_hash']);
        $this->assertStringNotContainsString($mentah, (string) json_encode(array_map(static fn ($v) => is_string($v) ? bin2hex($v) . $v : $v, $baris)), 'token mentah tidak tersimpan');
        $this->assertSame($id, $token->idCutiAktif($mentah));
        $this->assertNull($token->idCutiAktif(strtoupper($mentah)));

        $this->assertSame('http://localhost:8080/verify/surat-cuti/' . $mentah, $token->urlVerifikasi($mentah));
        $this->assertSame('http://localhost:8080/api/v1/publik/surat-cuti/' . $mentah . '/pdf', $token->urlPdfTte($id), 'token sama untuk QR TTE');
    }

    public function testQrKakiSuratBerisiUrlVerifikasiTanpaNip(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $surat                   = new SuratCutiService($this->db, null, $this->token());
        $cuti                    = $this->cutiService()->ambil($id);

        $url = (string) $surat->tautanVerifikasi($cuti);

        $this->assertMatchesRegularExpression('#/verify/surat-cuti/[0-9a-f]{64}$#', $url);
        $this->assertStringNotContainsString($a['pemohon']['nip'], $url, 'AC-02.8: QR kaki tidak memuat NIP');
        $this->assertStringNotContainsString('SIMPEG/CUTI', $url);
    }

    public function testNonaktifAktifRotasiDenganAuditDanRotasiSesudahTteDitolak(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $token                   = $this->token();
        $auth                    = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1));
        $lama                    = (string) $token->untukCuti($id);

        $token->nonaktifkan($auth, $id);
        $this->assertNull($token->idCutiAktif($lama));
        $this->assertNotNull($this->db->table('surat_cuti_token')->where('id_riwayat_cuti', $id)->get()->getRow()->nonaktif_at);
        $token->aktifkan($auth, $id);
        $this->assertSame($id, $token->idCutiAktif($lama));

        $baru = $token->rotasi($auth, $id);
        $this->assertNotSame($lama, $baru);
        $this->assertNull($token->idCutiAktif($lama), 'URL lama mati');
        $this->assertSame($id, $token->idCutiAktif($baru));

        $audit = $this->db->table('audit_logs')->where(['entity' => 'surat_cuti_token', 'entity_id' => (string) $id])->get()->getResultArray();
        $this->assertSame(['nonaktif', 'aktif', 'rotasi'], array_map(static fn (array $r): string => (string) json_decode((string) $r['after_json'], true)['aksi'], $audit));
        $this->assertStringNotContainsString($baru, (string) json_encode($audit), 'audit tanpa nilai token');

        // P3a review B4a: status TTE dibaca service sendiri dari lampiran surat ber-TTE (bukan argumen pemanggil).
        $this->esignService([], null, $token)->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        try {
            $token->rotasi($auth, $id);
            $this->fail('rotasi sesudah TTE harus ditolak (DBV-030 Q3)');
        } catch (ValidationException $e) {
            $this->assertSame([TokenSuratCutiService::PESAN_ROTASI_SESUDAH_TTE], $e->getErrors()['token']);
        }

        $this->assertSame($id, $token->idCutiAktif($baru), 'token tetap');
        $this->assertSame(3, $this->db->table('audit_logs')->where(['entity' => 'surat_cuti_token', 'entity_id' => (string) $id])->countAllResults(), 'penolakan tanpa audit');

        // Sesudah TTE: nonaktif / aktif tetap boleh.
        $this->assertSame(TokenSuratCutiService::NONAKTIF, $token->nonaktifkan(null, $id));
        $this->assertSame(TokenSuratCutiService::AKTIF, $token->aktifkan(null, $id));
    }

    /**
     * P3c review B4a: rotasi/nonaktif tidak bisa berjalan saat kunci surat cuti dipegang (mis. e-sign sedang membuat QR
     * ber-token) → 409, token tidak berubah, tanpa audit. Cuti tidak ada → 404.
     */
    public function testRotasiDanStatusDitolakSaatKunciDipegangDanCutiTidakAda404(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $token        = $this->token();
        $lama         = (string) $token->untukCuti($id);
        $lain         = \Config\Database::connect('tests', false);
        $nama         = KunciSuratCuti::nama($this->db, $id);

        try {
            $lain->query('SELECT GET_LOCK(?, 0)', [$nama]);

            foreach (['rotasi', 'nonaktifkan'] as $aksi) {
                try {
                    $token->{$aksi}(null, $id);
                    $this->fail("{$aksi} harus 409 saat kunci dipegang");
                } catch (ApiException $e) {
                    $this->assertSame([409, KunciSuratCuti::KODE_SIBUK], [$e->getStatusCode(), $e->getKode()]);
                }
            }
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$nama]);
            $lain->close();
        }

        $this->assertSame($id, $token->idCutiAktif($lama), 'token tidak berubah');
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'surat_cuti_token')->countAllResults());

        $this->expectException(NotFoundException::class);
        $token->rotasi(null, 999999);
    }

    public function testHapusCutiMenghapusTokenDiTransaksiSamaLalu404(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $mentah       = (string) $this->token()->untukCuti($id);
        $admin        = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1));

        $this->cutiService()->hapus($admin, $id);

        $this->assertSame(0, $this->db->table('surat_cuti_token')->where('id_riwayat_cuti', $id)->countAllResults());
        $this->assertNull($this->token()->idCutiAktif($mentah));
        $this->expectException(NotFoundException::class);
        (new VerifikasiSuratCutiService($this->token(), new SuratCutiService($this->db, $this->lampiranUji), $this->db))->verifikasi($mentah);
    }

    public function testAlurPublikUjungKeUjungLewatHttp(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->cutiService(); // menyiapkan lampiran palsu bersama ($this->lampiranUji)
        Services::injectMock('attachmentService', $this->lampiranUji);
        Services::injectMock('tokenSuratCuti', $this->token());
        $mentah = (string) $this->token()->untukCuti($id);

        $belum = $this->panggil("api/v1/publik/surat-cuti/{$mentah}");
        $belum->assertStatus(200);
        $this->assertSame('belum', $this->json($belum)['data']['status_tte']);
        $this->panggil("api/v1/publik/surat-cuti/{$mentah}/pdf")->assertStatus(404);

        $this->esignService([], null, $this->token())->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $sudah = $this->panggil("api/v1/publik/surat-cuti/{$mentah}");
        $this->assertSame('ditandatangani', $this->json($sudah)['data']['status_tte']);
        $this->assertStringNotContainsString($a['pemohon']['nip'], (string) $sudah->response()->getBody());

        $pdf = $this->panggil("api/v1/publik/surat-cuti/{$mentah}/pdf");
        $pdf->assertStatus(200);
        $this->assertSame('application/pdf', explode(';', $pdf->response()->getHeaderLine('Content-Type'))[0]);
        $this->assertStringStartsWith('inline', $pdf->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('noindex, nofollow', $pdf->response()->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $pdf->response()->getHeaderLine('Cache-Control'));

        $this->token()->nonaktifkan($this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1)), $id);
        $this->panggil("api/v1/publik/surat-cuti/{$mentah}/pdf")->assertStatus(404);
    }

    private function token(): TokenSuratCutiService
    {
        $this->cutiService(); // menyiapkan lampiran palsu bersama ($this->lampiranUji) untuk cek surat ber-TTE

        return new TokenSuratCutiService($this->db, 'http://localhost:8080/verify/surat-cuti/', 'http://localhost:8080/api/v1/publik/surat-cuti/', null, $this->lampiranUji);
    }

    private function panggil(string $url): \CodeIgniter\Test\TestResponse
    {
        service('superglobals')->setServer('REMOTE_ADDR', '198.51.100.91');

        try {
            return $this->withHeaders([])->get($url);
        } finally {
            Services::resetSingle('response');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\CodeIgniter\Test\TestResponse $res): array
    {
        return (array) json_decode((string) $res->response()->getBody(), true);
    }
}
