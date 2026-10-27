<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\ValidasiTanggalCuti;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-022 Tahap 2b — validasi tanggal PRD Fase 4 v1.1 §6.2.3 + batas jenis 5/6 §6.3.2: T2, T3, T7, T8 (§6.3.10),
 * durasi cuti Besar, pengumpulan semua galat (A-09). Hari ini = 2027-01-10, tahun acuan 2027.
 *
 * @internal
 */
final class ValidasiTanggalCutiTest extends CIUnitTestCase
{
    private const HARI_INI = '2027-01-10';

    private const TAHUN = 2027;

    private AturanCuti $aturan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aturan = AturanCuti::bawaan();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function batasAlasanPenting(): iterable
    {
        yield '15-01 → 15-02' => ['2025-01-15', '2025-02-15'];

        yield '31-01-2025 → 03-03' => ['2025-01-31', '2025-03-03'];

        yield '31-01-2024 → 02-03 (kabisat)' => ['2024-01-31', '2024-03-02'];

        yield '30-04 → 30-05' => ['2025-04-30', '2025-05-30'];
    }

    #[DataProvider('batasAlasanPenting')]
    public function testT2BatasAlasanPentingPhpSatuBulan(string $mulai, string $batas): void
    {
        $this->assertSame($batas, ValidasiTanggalCuti::batasAlasanPenting($mulai, $this->aturan));

        // Jenis 5 boleh mundur: hari ini jauh di depan tetap sah; tahun acuan = tahun mulai.
        $tahun = (int) substr($mulai, 0, 4);
        $ok    = ValidasiTanggalCuti::periksa(5, $mulai, $batas, null, '2030-01-01', $tahun, $this->aturan);
        $this->assertSame([], $ok['errors']);
        $this->assertSame($batas, $ok['tgl_akhir']);

        $lewat = ValidasiTanggalCuti::periksa(5, $mulai, self::tambahHari($batas), null, '2030-01-01', $tahun, $this->aturan);
        $this->assertSame(['tgl_akhir' => 'Lama cuti karena alasan penting maksimal 1 bulan.'], $lewat['errors']);
        $this->assertNull($lewat['tgl_akhir']);
    }

    public function testT3BatasCltnTigaTahunInklusif(): void
    {
        $this->assertSame('2027-03-01', ValidasiTanggalCuti::batasCltn('2024-02-29', $this->aturan));
        $this->assertSame('2030-01-10', ValidasiTanggalCuti::batasCltn('2027-01-10', $this->aturan));

        $ok = ValidasiTanggalCuti::periksa(6, '2027-01-10', '2030-01-10', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $ok['errors']);

        $lewat = ValidasiTanggalCuti::periksa(6, '2027-01-10', '2030-01-11', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['tgl_akhir' => 'Lama cuti di luar tanggungan negara maksimal 3 tahun.'], $lewat['errors']);
    }

    public function testT7TahunanMaksimal3112TahunBerikutnya(): void
    {
        $ok = ValidasiTanggalCuti::periksa(1, '2028-12-27', '2028-12-31', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $ok['errors']);

        $lewat = ValidasiTanggalCuti::periksa(1, '2028-12-27', '2029-01-03', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['tgl_akhir' => 'Tanggal akhir cuti maksimal 31-12-2028.'], $lewat['errors']);

        // Batas dua tahun hanya untuk kuota (jenis 1): CLTN 3 tahun tetap sah.
        $cltn = ValidasiTanggalCuti::periksa(6, '2027-02-01', '2029-06-30', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $cltn['errors']);
    }

    public function testT8TanggalTahunSebelumTahunPengajuan(): void
    {
        // Jenis 3 boleh mundur, tetapi tidak ke tahun sebelum tahun acuan (keputusan #6).
        $hasil = ValidasiTanggalCuti::periksa(3, '2026-12-28', '2026-12-30', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['tgl_mulai' => 'Tanggal cuti tidak boleh pada tahun sebelum tahun pengajuan.'], $hasil['errors']);

        // Jenis 1 pada tanggal lampau: pesan "sebelum hari ini" lebih dulu.
        $hasil = ValidasiTanggalCuti::periksa(1, '2026-12-28', '2026-12-30', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['tgl_mulai' => 'Tanggal mulai cuti tidak boleh sebelum hari ini.'], $hasil['errors']);

        // Jenis 3 mundur di tahun yang sama: sah.
        $hasil = ValidasiTanggalCuti::periksa(3, '2027-01-04', '2027-01-06', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $hasil['errors']);
    }

    public function testCutiBesarDurasiDanTglAkhirDihitung(): void
    {
        $hasil = ValidasiTanggalCuti::periksa(2, '2027-01-31', null, 1, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $hasil['errors']);
        $this->assertSame('2027-02-27', $hasil['tgl_akhir']);

        $hasil = ValidasiTanggalCuti::periksa(2, '2027-01-31', '2027-12-31', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['durasi' => 'Silahkan pilih durasi cuti besar.'], $hasil['errors']);
        $this->assertNull($hasil['tgl_akhir']);

        $hasil = ValidasiTanggalCuti::periksa(2, '2027-01-31', null, 4, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['durasi' => 'Silahkan pilih durasi cuti besar.'], $hasil['errors']);
    }

    public function testSemuaGalatDikumpulkan(): void
    {
        $hasil = ValidasiTanggalCuti::periksa(1, '', null, null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(
            ['tgl_mulai' => 'Tanggal mulai cuti wajib diisi.', 'tgl_akhir' => 'Tanggal akhir cuti wajib diisi.'],
            $hasil['errors'],
        );
        $this->assertNull($hasil['tgl_akhir']);

        $hasil = ValidasiTanggalCuti::periksa(1, '2027-02-30', '31/12/2027', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(
            ['tgl_mulai' => 'Tanggal mulai cuti tidak valid.', 'tgl_akhir' => 'Tanggal akhir cuti tidak valid.'],
            $hasil['errors'],
        );

        $hasil = ValidasiTanggalCuti::periksa(1, '2027-02-10', '2027-02-09', null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame(['tgl_akhir' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.'], $hasil['errors']);

        $hasil = ValidasiTanggalCuti::periksa(1, self::HARI_INI, self::HARI_INI, null, self::HARI_INI, self::TAHUN, $this->aturan);
        $this->assertSame([], $hasil['errors']);
        $this->assertSame(self::HARI_INI, $hasil['tgl_akhir']);
    }

    public function testPeriksaLamaNol(): void
    {
        $this->assertSame('Lama cuti tidak boleh 0 hari.', ValidasiTanggalCuti::periksaLama(0));
        $this->assertNull(ValidasiTanggalCuti::periksaLama(1));
    }

    private static function tambahHari(string $tanggal): string
    {
        [$y, $m, $d] = explode('-', $tanggal);
        $ts          = gmmktime(0, 0, 0, (int) $m, (int) $d + 1, (int) $y);

        return gmdate('Y-m-d', $ts);
    }
}
