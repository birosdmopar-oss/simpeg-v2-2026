<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Libraries\Presensi\Laporan\KopLaporanTukin;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Kop PDF laporan Tukin per pegawai — cabang alamat & logo kanan ikut legacy [K] `L_presensi.php:6512-6521` + `:7105-7116`
 * (keputusan Horii 29-10-2026, G1/G2 ikut legacy). Unit "6"/"7" legacy = `$unitTtdSatkerTerpilih` /
 * `$unitTtdSatkerKepegawaian`, di test diarahkan ke unit fixture.
 *
 * @internal
 */
final class KopLaporanTukinTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const ALAMAT_KEMENTERIAN = 'Jalan Kementerian 1';

    private string $akar;

    /** @var array<string, string> isi base64 per berkas logo */
    private array $logo = [];

    private Presensi $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kop_tukin_' . bin2hex(random_bytes(4));
        mkdir($this->akar);

        foreach (['kem.png' => 20, 'won.png' => 21, 'uns.png' => 22] as $berkas => $sisi) {
            $gambar = imagecreatetruecolor($sisi, $sisi);
            imagepng($gambar, $this->akar . DIRECTORY_SEPARATOR . $berkas);
            imagedestroy($gambar);
            $this->logo[$berkas] = base64_encode((string) file_get_contents($this->akar . DIRECTORY_SEPARATOR . $berkas));
        }

        $this->config           = new Presensi();
        $this->config->akarAset = $this->akar . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->logo) as $berkas) {
            @unlink($this->akar . DIRECTORY_SEPARATOR . $berkas);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testUnitBiasaDenganAlamatUnit(): void
    {
        [$unit, $satker] = $this->unitSatker('Jalan Unit 2<br>Jakarta', 'Jalan Satker 3');
        $kop             = $this->kop($unit, $satker);

        $this->assertStringContainsString('Jalan Unit 2<br />' . "\n" . 'Jakarta', $kop);
        $this->assertStringNotContainsString(self::ALAMAT_KEMENTERIAN, $kop);
        $this->assertLogoKanan('won.png', $kop);
    }

    public function testUnitBiasaAlamatKosongPakaiKementerian(): void
    {
        [$unit, $satker] = $this->unitSatker('<br>', 'Jalan Satker 3');
        $kop             = $this->kop($unit, $satker);

        $this->assertStringContainsString(self::ALAMAT_KEMENTERIAN, $kop);
        $this->assertStringNotContainsString('Jalan Satker 3', $kop);
        $this->assertLogoKanan('won.png', $kop);
    }

    /** G1: unit 7 dengan alamat unit terisi → alamat kementerian (legacy tidak memakai alamat unit 6/7). */
    public function testUnit7AlamatUnitTerisiPakaiKementerian(): void
    {
        [$unit, $satker]                        = $this->unitSatker('Jalan Unit 2', 'Jalan Satker 3');
        $this->config->unitTtdSatkerKepegawaian = $unit;
        $kop                                    = $this->kop($unit, $satker);

        $this->assertStringContainsString(self::ALAMAT_KEMENTERIAN, $kop);
        $this->assertStringNotContainsString('Jalan Unit 2', $kop);
        $this->assertStringNotContainsString('Jalan Satker 3', $kop);
        $this->assertLogoKanan('won.png', $kop);
        $this->assertMatchesRegularExpression('/<b>SATKER FIXTURE [^<]*<\/b>/', $kop, 'nama satker untuk unit 7');
    }

    public function testUnit6AlamatSatkerPakaiAlamatDanLogoSatker(): void
    {
        [$unit, $satker]                     = $this->unitSatker('Jalan Unit 2', 'Jalan Satker 3');
        $this->config->unitTtdSatkerTerpilih = $unit;
        $kop                                 = $this->kop($unit, $satker);

        $this->assertStringContainsString('Jalan Satker 3', $kop);
        $this->assertStringNotContainsString('Jalan Unit 2', $kop);
        $this->assertStringNotContainsString(self::ALAMAT_KEMENTERIAN, $kop);
        $this->assertLogoKanan('uns.png', $kop);
        $this->assertMatchesRegularExpression('/<b>SATKER FIXTURE [^<]*<\/b>/', $kop);
    }

    /** G2: unit 6 tanpa alamat satker → alamat kementerian + logo wonderful (bukan logo satker). */
    public function testUnit6AlamatSatkerKosongPakaiKementerianDanWonderful(): void
    {
        [$unit, $satker]                     = $this->unitSatker('Jalan Unit 2', '');
        $this->config->unitTtdSatkerTerpilih = $unit;
        $kop                                 = $this->kop($unit, $satker);

        $this->assertStringContainsString(self::ALAMAT_KEMENTERIAN, $kop);
        $this->assertStringNotContainsString('Jalan Unit 2', $kop);
        $this->assertLogoKanan('won.png', $kop);
    }

    /** [V2] logo satker tidak ada → jatuh ke wonderful; keduanya tidak ada → sel kosong. */
    public function testUnit6LogoSatkerTidakAdaJatuhKeWonderful(): void
    {
        [$unit, $satker]                     = $this->unitSatker('', 'Jalan Satker 3', 'tidak-ada.png');
        $this->config->unitTtdSatkerTerpilih = $unit;

        $kop = $this->kop($unit, $satker);
        $this->assertStringContainsString('Jalan Satker 3', $kop);
        $this->assertLogoKanan('won.png', $kop);

        $kop = KopLaporanTukin::html($this->db, $this->wc(['logo_wonderful_pdf' => 'tidak-ada.png']), $this->config, $unit, $satker);
        $this->assertSame(1, substr_count($kop, '<img '), 'hanya logo kiri');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function unitSatker(string $alamatUnit, string $alamatSatker, string $logoSatker = 'uns.png'): array
    {
        $unit   = $this->buatUnit(['alamat_pdf_header' => $alamatUnit]);
        $satker = $this->buatSatker($unit, ['alamat_pdf_header' => $alamatSatker, 'logo_uns' => $logoSatker]);

        return [$unit, $satker];
    }

    private function kop(int $unit, int $satker): string
    {
        return KopLaporanTukin::html($this->db, $this->wc(), $this->config, $unit, $satker);
    }

    /**
     * @param array<string, mixed> $ganti
     *
     * @return array<string, mixed>
     */
    private function wc(array $ganti = []): array
    {
        return $ganti + [
            'pdf_header_nama_kementerian'   => 'Kementerian Uji',
            'pdf_header_alamat_kementerian' => self::ALAMAT_KEMENTERIAN,
            'logo_kementerian_pdf'          => 'kem.png',
            'logo_wonderful_pdf'            => 'won.png',
        ];
    }

    private function assertLogoKanan(string $berkas, string $kop): void
    {
        $this->assertStringContainsString($this->logo['kem.png'], $kop, 'logo kiri kementerian');

        foreach (['won.png', 'uns.png'] as $calon) {
            $this->assertSame($calon === $berkas ? 1 : 0, substr_count($kop, $this->logo[$calon]), 'logo kanan ' . $berkas);
        }
    }
}
