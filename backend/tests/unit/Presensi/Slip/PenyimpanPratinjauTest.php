<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\PenyimpanPratinjau;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;

/**
 * PRD 5A §7.2.2 butir 5–6, §7.2.4 butir 2–3/7, §7.2.5 (AC-D10.7, AC-D10.8 bagian berkas, AC-D10.10): penyimpan
 * pratinjau impor — nama acak (bukan nama klien), pemilik token, TTL, satu pratinjau aktif per pengguna, hash isi.
 *
 * @internal
 */
final class PenyimpanPratinjauTest extends CIUnitTestCase
{
    private const T0 = 1_790_000_000;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slip-impor-uji-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testSimpanLaluMuatOlehPemilik(): void
    {
        $p     = $this->penyimpan();
        $hasil = $p->simpan('ISI-EXCEL', 'XLSX', $this->meta(7), self::T0);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $hasil['token']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0 + 1800), $hasil['kedaluwarsa_at']);

        $berkas = glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [];
        $this->assertCount(2, $berkas, 'berkas + sidecar JSON');
        $hashToken = hash('sha256', $hasil['token']);
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . $hashToken . '.xlsx', 'nama = sha256(token), bukan nama klien');
        $this->assertStringNotContainsString('gaji-asli', implode('|', $berkas));

        $muat = $p->muat($hasil['token'], 7, self::T0 + 10);
        $this->assertNotNull($muat);
        $this->assertSame('ISI-EXCEL', file_get_contents($muat['path']));
        $this->assertSame('gaji-asli.xlsx', $muat['meta']['nama_berkas']);
        $this->assertSame(['bulan' => 9, 'tahun' => 2026], $muat['meta']['filter']);
    }

    public function testBukanPemilikTokenSalahAtauTtlLewatNull(): void
    {
        $p     = $this->penyimpan();
        $token = $p->simpan('ISI', 'xlsx', $this->meta(7), self::T0)['token'];

        $this->assertNull($p->muat($token, 8, self::T0 + 10), 'pengguna lain');
        $this->assertNull($p->muat(strtoupper($token), 7, self::T0 + 10), 'format token tidak sah');
        $this->assertNull($p->muat(str_repeat('a', 64), 7, self::T0 + 10), 'token tidak ada');
        $this->assertNotNull($p->muat($token, 7, self::T0 + 1799));

        $this->assertNull($p->muat($token, 7, self::T0 + 1800), 'TTL lewat (inklusif)');
        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [], 'kedaluwarsa → berkas terhapus');
    }

    public function testIsiBerubahDitolak(): void
    {
        $p     = $this->penyimpan();
        $token = $p->simpan('ISI-ASLI', 'xls', $this->meta(7), self::T0)['token'];
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.xls', 'ISI-DIUBAH');

        $this->assertNull($p->muat($token, 7, self::T0 + 10));
    }

    public function testSatuPratinjauAktifPerPenggunaDanSapuKedaluwarsa(): void
    {
        $p    = $this->penyimpan();
        $lama = $p->simpan('A', 'xlsx', $this->meta(7), self::T0)['token'];
        $lain = $p->simpan('B', 'xlsx', $this->meta(9), self::T0)['token'];
        $baru = $p->simpan('C', 'xlsx', $this->meta(7), self::T0 + 5)['token'];

        $this->assertNull($p->muat($lama, 7, self::T0 + 10), 'unggahan baru menghapus pratinjau lama pengguna sama');
        $this->assertNotNull($p->muat($baru, 7, self::T0 + 10));
        $this->assertNotNull($p->muat($lain, 9, self::T0 + 10), 'pratinjau pengguna lain tetap');

        // Simpan berikutnya menyapu pratinjau kedaluwarsa milik siapa pun.
        $p->simpan('D', 'xlsx', $this->meta(11), self::T0 + 4000);
        $this->assertCount(2, glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [], 'hanya pratinjau pengguna 11 tersisa');
    }

    public function testHapus(): void
    {
        $p     = $this->penyimpan();
        $token = $p->simpan('A', 'xlsx', $this->meta(7), self::T0)['token'];
        $p->hapus($token);

        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: []);
        $this->assertNull($p->muat($token, 7, self::T0 + 1));
    }

    public function testConfigBatasImporDanOverrideEnv(): void
    {
        $config = new Presensi();
        $this->assertSame(5 * 1024 * 1024, $config->maksUkuranImporByte);
        $this->assertSame(10000, $config->maksBarisImporPerSheet);
        $this->assertSame(1800, $config->ttlPratinjauDetik);
        $this->assertStringEndsWith('uploads/slip-impor/', str_replace('\\', '/', $config->direktoriPratinjau));

        $this->assertSame(600, Presensi::angkaPositif('600'));
        $this->assertSame(600, Presensi::angkaPositif(' 600 '));
        foreach (['0', '-5', '1e3', '10.5', '', 'abc', '99999999999'] as $tidakSah) {
            $this->assertNull(Presensi::angkaPositif($tidakSah), $tidakSah);
        }
    }

    private function penyimpan(): PenyimpanPratinjau
    {
        return new PenyimpanPratinjau($this->dir, 1800);
    }

    /**
     * @return array{id_pengguna: int, role: int, id_satker: ?string, filter: array<string, mixed>, nama_berkas: string}
     */
    private function meta(int $idPengguna): array
    {
        return ['id_pengguna' => $idPengguna, 'role' => 1, 'id_satker' => null, 'filter' => ['bulan' => 9, 'tahun' => 2026], 'nama_berkas' => 'gaji-asli.xlsx'];
    }
}
