<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Config\Services;
use LogicException;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-167 — commit impor slip membaca dan menguraikan berkas SEBELUM transaksi dibuka. Saat parse dimulai, kedalaman
 * transaksi koneksi masih sama dengan sebelum commit dipanggil (dulu sudah naik satu: `transBegin` mendahului parse);
 * tulis tetap berlangsung di dalam transaksi. Titik "parse dimulai" diamati lewat config tiruan: `PembacaExcelSlip` dibuat
 * dengan `maksBarisImporPerSheet` tepat sebelum berkas dibaca. Status dan pesan galat dikunci test pagar
 * `ImporSlipParitasTest`. Data & NIP sintetis.
 *
 * @internal
 */
final class ImporSlipParseSebelumTransaksiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private int $maksBaris;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->maksBaris = config(Presensi::class)->maksBarisImporPerSheet;
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->maksBarisImporPerSheet = $this->maksBaris;
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testGalatParseSaatCommitTerjadiSebelumTransaksiDibuka(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $kedalaman             = $this->db->transDepth;
        $saatParse             = [];
        $config                = $this->configPengamat(0, function () use (&$saatParse): void {
            $saatParse[] = $this->db->transDepth;
        });

        try {
            (new ImporSlipService(null, $config))->commit($this->auth($admin), $token, true);
            $this->fail('harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, ['berkas' => ['Jumlah baris melebihi batas 0 per sheet.']]], [$e->getStatusCode(), $e->getErrors()]);
        }

        $this->assertSame([$kedalaman], $saatParse, 'parse berjalan sekali, sebelum transBegin');
        $this->assertSame($kedalaman, $this->db->transDepth);
        $this->assertSame(0, $this->db->table('gaji_pegawai')->where('nip', $nip)->countAllResults());
        $this->assertSame([], $this->isiDirPratinjau());
    }

    /**
     * Galat non-ApiException saat parse (di luar transaksi) tetap dipetakan seperti saat parse masih di dalam transaksi:
     * dicatat `Commit impor slip gagal` lalu 500 generik, tanpa transaksi dibuka.
     */
    public function testGalatNonApiSaatParseTetap500GagalCommit(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $kedalaman             = $this->db->transDepth;
        $config                = $this->configPengamat(0, static function (): void {
            throw new RuntimeException('parse uji gagal');
        });

        try {
            (new ImporSlipService(null, $config))->commit($this->auth($admin), $token, true);
            $this->fail('harus 500');
        } catch (ApiException $e) {
            $this->assertSame([500, ImporSlipService::PESAN_GAGAL_COMMIT, null, null], [$e->getStatusCode(), $e->getMessage(), $e->getKode(), $e->getErrors()]);
        }

        $this->assertLogContains('error', 'Commit impor slip gagal: RuntimeException parse uji gagal');
        $this->assertSame($kedalaman, $this->db->transDepth);
        $this->assertSame(0, $this->db->table('gaji_pegawai')->where('nip', $nip)->countAllResults());
        $this->assertSame([], $this->isiDirPratinjau());
    }

    public function testCommitSuksesParseDuluBaruTransaksiDanTulisDiDalamnya(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $kedalaman             = $this->db->transDepth;
        $urutan                = [];
        $config                = $this->configPengamat($this->maksBaris, function () use (&$urutan): void {
            $urutan[] = ['parse', $this->db->transDepth];
        });
        $kait = function () use (&$urutan): void {
            $urutan[] = ['tulis', $this->db->transDepth];
        };

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            $hasil = (new ImporSlipService(null, $config, null, null, $kait))->commit($this->auth($admin), $token, true);
        } finally {
            $hitung->selesai();
        }

        $this->assertSame([1, 0], [$hasil['disisip'], $hasil['diperbarui']]);
        $this->assertSame([['parse', $kedalaman], ['tulis', $kedalaman + 1]], $urutan, 'parse di luar transaksi, tulis di dalamnya');
        $this->assertSame($kedalaman, $this->db->transDepth);
        $kunci = array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE')));
        $this->assertCount(1, $kunci);
        $this->assertStringContainsString($nip, $kunci[0]);
        $this->assertSame(1, $this->db->table('gaji_pegawai')->where('nip', $nip)->countAllResults());
    }

    /**
     * Config Presensi yang memanggil `$saatDibaca` setiap `maksBarisImporPerSheet` dibaca (= saat parse berkas dimulai);
     * direktori dan TTL pratinjau ikut config uji yang sedang berlaku.
     */
    private function configPengamat(int $maksBaris, Closure $saatDibaca): Presensi
    {
        $asli   = config(Presensi::class);
        $config = new class () extends Presensi {
            public int $nilaiMaksBaris = 0;

            public ?Closure $saatDibaca = null;

            public function __construct()
            {
                parent::__construct();
                // Properti dilepas supaya pembacaannya lewat __get() (titik amat parse); hanya untuk config tiruan ini.
                unset($this->maksBarisImporPerSheet); // @phpstan-ignore unset.possiblyHookedProperty
            }

            public function __get(string $nama): mixed
            {
                if ($nama !== 'maksBarisImporPerSheet') {
                    throw new LogicException("Properti config tidak dikenal: {$nama}");
                }

                if ($this->saatDibaca !== null) {
                    ($this->saatDibaca)();
                }

                return $this->nilaiMaksBaris;
            }
        };
        $config->direktoriPratinjau = $asli->direktoriPratinjau;
        $config->ttlPratinjauDetik  = $asli->ttlPratinjauDetik;
        $config->nilaiMaksBaris     = $maksBaris;
        $config->saatDibaca         = $saatDibaca;

        return $config;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function siapkan(): array
    {
        $nip   = $this->buatPegawai(['nama' => 'Dewi Empat']);
        $admin = $this->adminSlip();
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($nip, 9, 2026, 'Dewi Empat')], [$this->barisTk($nip, 9, 2026, 'Dewi Empat')])))['token_pratinjau'];
        Services::resetSingle('response');

        return [$nip, $admin, (string) $token];
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function auth(array $akun): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $akun['id_pengguna'], 'id_pengguna' => (int) $akun['id_pengguna'], 'role' => (int) $akun['user_level'], 'id_satker' => $akun['id_satker'], 'nip' => null]);

        return $auth;
    }
}
