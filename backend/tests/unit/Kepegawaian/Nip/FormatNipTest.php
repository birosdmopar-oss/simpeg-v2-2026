<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Nip;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\UserService;
use App\Libraries\Kepegawaian\Nip\FormatNip;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * CR-036 — format NIP Kepegawaian: kasus batas, koreksi NIP (legacy `update_nip`), dan keselarasan dengan aturan akun
 * Auth (DBV-010). Semua NIP di sini sintetis.
 *
 * @internal
 */
final class FormatNipTest extends CIUnitTestCase
{
    /**
     * NIP/NIK sintetis yang diterima: 1 digit, nomor pendek pegawai lama, NIK 16 digit, NIP 18 digit, nol di depan.
     */
    private const VALID = ['1', '12345', '3201010101900001', '199001012015031001', '000000000000000000', '099999999999999999'];

    /**
     * Ditolak: 19 digit, spasi/baris baru di dalam atau di ujung (`valid()` tidak men-trim), tanda, desimal, notasi
     * ilmiah, huruf, digit non-ASCII (lebar penuh, Arab-Indik), dan byte NUL.
     */
    private const TIDAK_VALID = [
        '', '1990010120150310011', ' 123', '123 ', "123\n", "\t123", '12 34', '12-34', '+123', '-1', '1.5', '1e5',
        'abc', '19900101201503100A', '１２３', '١٢٣', "12\x0034", '0x1F',
    ];

    public function testValid(): void
    {
        foreach (self::VALID as $nip) {
            $this->assertTrue(FormatNip::valid($nip), var_export($nip, true));
        }

        foreach (self::TIDAK_VALID as $nip) {
            $this->assertFalse(FormatNip::valid($nip), var_export($nip, true));
        }
    }

    public function testBatasPanjang(): void
    {
        $this->assertSame(18, FormatNip::MAKS_DIGIT);
        $this->assertTrue(FormatNip::valid(str_repeat('9', 18)));
        $this->assertFalse(FormatNip::valid(str_repeat('9', 19)));
        $this->assertTrue(FormatNip::valid(str_repeat('9', 16)));
    }

    public function testPeriksaKosongWajib(): void
    {
        foreach ([null, '', '   ', "\n"] as $nip) {
            $hasil = FormatNip::periksa($nip);
            $this->assertFalse($hasil->valid, var_export($nip, true));
            $this->assertSame('nip_wajib', $hasil->kode);
            $this->assertSame('NIP wajib diisi.', $hasil->pesan);
        }
    }

    public function testPeriksaMenormalkanSpasiDiUjung(): void
    {
        $hasil = FormatNip::periksa("  199001012015031001\n");

        $this->assertTrue($hasil->valid);
        $this->assertNull($hasil->kode);
        $this->assertNull($hasil->tanggal);
        $this->assertSame(['nip' => '199001012015031001'], $hasil->detail);
    }

    public function testPeriksaFormatSalah(): void
    {
        $bukanTeks = [199001012015031001, 1.5, true, ['199001012015031001']];

        foreach (array_merge(['1990010120150310011', '12 34', 'abc', '１２３'], $bukanTeks) as $nip) {
            $hasil = FormatNip::periksa($nip);
            $this->assertFalse($hasil->valid, var_export($nip, true));
            $this->assertSame('nip_tidak_valid', $hasil->kode);
            $this->assertSame('NIP harus berupa angka, maksimal 18 digit.', $hasil->pesan);
        }

        $this->assertSame('NIP baru harus berupa angka, maksimal 18 digit.', FormatNip::periksa('x', 'NIP baru')->pesan);
    }

    public function testGagalMenjadi422PerField(): void
    {
        try {
            FormatNip::periksa('12a')->lemparBilaGagal('nip');
            $this->fail('ValidationException tidak dilempar');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(['nip' => ['NIP harus berupa angka, maksimal 18 digit.']], $e->getErrors());
        }
    }

    public function testPeriksaKoreksiNipLamaWajib(): void
    {
        foreach ([null, '', '  ', 123] as $lama) {
            $hasil = FormatNip::periksaKoreksi($lama, '199001012015031001');
            $this->assertFalse($hasil->valid, var_export($lama, true));
            $this->assertSame('nip_lama_wajib', $hasil->kode);
        }
    }

    public function testPeriksaKoreksiNipBaru(): void
    {
        $kosong = FormatNip::periksaKoreksi('199001012015031001', '');
        $this->assertSame('nip_wajib', $kosong->kode);
        $this->assertSame('NIP baru wajib diisi.', $kosong->pesan);

        $salah = FormatNip::periksaKoreksi('199001012015031001', '1990010120150310011');
        $this->assertSame('nip_tidak_valid', $salah->kode);
        $this->assertSame('NIP baru harus berupa angka, maksimal 18 digit.', $salah->pesan);
    }

    public function testPeriksaKoreksiNipBaruSamaDitolak(): void
    {
        foreach (['199001012015031001', ' 199001012015031001 '] as $baru) {
            $hasil = FormatNip::periksaKoreksi(' 199001012015031001', $baru);
            $this->assertFalse($hasil->valid);
            $this->assertSame('nip_baru_sama', $hasil->kode);
            $this->assertSame('NIP baru sama dengan NIP lama.', $hasil->pesan);
        }
    }

    public function testPeriksaKoreksiBerhasil(): void
    {
        // NIK Non-PNS → NIP PNS 18 digit; format NIP lama tidak divalidasi (data tersimpan).
        $hasil = FormatNip::periksaKoreksi('3201010101900001', ' 199001012015031001 ');

        $this->assertTrue($hasil->valid);
        $this->assertSame(['nip_lama' => '3201010101900001', 'nip_baru' => '199001012015031001'], $hasil->detail);

        $this->assertTrue(FormatNip::periksaKoreksi('PTT-01', '12345')->valid);
    }

    /**
     * Aturan akun Auth (`UserService::validateNip`, `PenggunaModel::NIP_MAX_DIGITS`) dan FormatNip harus sama. Auth men-trim
     * isian sebelum validasi, jadi perbandingan memakai string yang sudah di-trim. Bila salah satu berubah, test ini gagal
     * dan keduanya harus disesuaikan bersama.
     */
    public function testSelarasDenganAturanAkunAuth(): void
    {
        $this->assertSame(PenggunaModel::NIP_MAX_DIGITS, FormatNip::MAKS_DIGIT);

        $validateNip = new ReflectionMethod(UserService::class, 'validateNip');
        $kasus       = array_merge(self::VALID, array_filter(self::TIDAK_VALID, static fn (string $s): bool => $s !== '' && trim($s) === $s));

        foreach ($kasus as $nip) {
            /** @var array<string, list<string>> $errors */
            $errors = [];
            $auth   = $validateNip->invokeArgs(null, [$nip, &$errors]);

            $this->assertSame($auth, FormatNip::valid($nip), 'beda dengan Auth: ' . var_export($nip, true));

            if (! $auth) {
                $this->assertSame([(string) FormatNip::periksa($nip)->pesan], $errors['nip']);
            }
        }
    }
}
