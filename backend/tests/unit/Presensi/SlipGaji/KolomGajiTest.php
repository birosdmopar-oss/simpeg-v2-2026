<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-027 (I-1..I-3) — 28 field nominal, peta kolom Excel → field, normalisasi header, dan pencarian sheet.
 *
 * @internal
 */
final class KolomGajiTest extends CIUnitTestCase
{
    /**
     * Header lengkap sheet GPP dengan gaya huruf besar template legacy.
     *
     * @return list<string>
     */
    public static function headerGpp(): array
    {
        return ['NIP', 'BULAN', 'TAHUN', ...array_map('strtoupper', array_keys(KolomGaji::SUMBER_GPP))];
    }

    public function testDuaPuluhDelapanField(): void
    {
        $semua = KolomGaji::semuaField();

        $this->assertCount(28, $semua);
        $this->assertSame($semua, array_values(array_unique($semua)));
        $this->assertCount(22, KolomGaji::GPP);
        $this->assertCount(6, KolomGaji::TK);
        $this->assertCount(13, KolomGaji::PENGHASILAN_GPP);
        $this->assertCount(8, KolomGaji::POTONGAN_GPP);

        $gpp = [...KolomGaji::PENGHASILAN_GPP, ...KolomGaji::POTONGAN_GPP, 'bersih_gpp'];
        sort($gpp);
        $kunciGpp = array_keys(KolomGaji::GPP);
        sort($kunciGpp);
        $this->assertSame($kunciGpp, $gpp);

        // Daftar potongan eksplisit sama dengan aturan prefiks `pot` legacy.
        foreach (array_keys(KolomGaji::GPP) as $field) {
            if ($field !== 'bersih_gpp') {
                $this->assertSame(str_starts_with($field, 'pot'), in_array($field, KolomGaji::POTONGAN_GPP, true), $field);
            }
        }
    }

    public function testPetaSumber(): void
    {
        $this->assertSame('bersih_gpp', KolomGaji::SUMBER_GPP['bersih']);
        $this->assertSame('bersih_tk', KolomGaji::SUMBER_TK['bersih']);
        $this->assertSame(array_keys(KolomGaji::GPP), array_values(KolomGaji::SUMBER_GPP));
        $this->assertSame(array_keys(KolomGaji::TK), array_values(KolomGaji::SUMBER_TK));
        $this->assertSame(KolomGaji::SUMBER_GPP, KolomGaji::sumber('GPP'));
        $this->assertSame(['kotor', 'bersih'], KolomGaji::wajibTerisi('TK'));

        $this->expectException(InvalidArgumentException::class);
        KolomGaji::sumber('gpp');
    }

    public function testNormalisasiHeader(): void
    {
        $kasus = [
            'NIP'               => 'nip',
            ' Tunj Pajak '      => 'tunj_pajak',
            'TUNJ-PAJAK'        => 'tunj_pajak',
            'BERSIH 2'          => 'bersih_2',
            'TUNJ  PAJAK'       => 'tunj_pajak',
            'TUNJ _PAJAK'       => 'tunj_pajak',
            "TUNJ\u{00A0}PAJAK" => 'tunj_pajak',
            'Tunj.Pajak'        => 'tunj_pajak',
            '_bersih_'          => 'bersih',
            '   '               => '',
        ];

        foreach ($kasus as $header => $harapan) {
            $this->assertSame($harapan, KolomGaji::normalisasiHeader($header), var_export($header, true));
        }
    }

    public function testPetakanHeaderBerhasil(): void
    {
        $header = [...self::headerGpp(), 'Keterangan', null, 'Nama'];
        $hasil  = KolomGaji::petakanHeader($header, 'GPP');

        $this->assertTrue($hasil->valid, (string) $hasil->pesan);
        $this->assertIsArray($hasil->nilai);
        $this->assertSame(0, $hasil->nilai['kolom']['nip']);
        $this->assertSame(2, $hasil->nilai['kolom']['tahun']);
        $this->assertSame(3, $hasil->nilai['kolom']['gjpokok']);
        $this->assertSame(24, $hasil->nilai['kolom']['bersih_gpp']);
        $this->assertArrayNotHasKey('bersih', $hasil->nilai['kolom']);
        $this->assertSame(27, $hasil->nilai['nama']);
        $this->assertSame(['keterangan'], $hasil->nilai['diabaikan']);
        $this->assertSame('kolom_diabaikan', $hasil->kode);

        $tanpaNama = KolomGaji::petakanHeader(self::headerGpp(), 'GPP');
        $this->assertNull($tanpaNama->nilai['nama']);
        $this->assertNull($tanpaNama->kode);
    }

    public function testPetakanHeaderTanpaKolomKunci(): void
    {
        $header = array_values(array_filter(self::headerGpp(), static fn (string $h): bool => $h !== 'TAHUN'));
        $hasil  = KolomGaji::petakanHeader($header, 'GPP');

        $this->assertFalse($hasil->valid);
        $this->assertSame('kolom_kunci_tidak_ada', $hasil->kode);
        $this->assertSame('Sheet GPP tidak memiliki kolom wajib: TAHUN.', $hasil->pesan);
    }

    public function testPetakanHeaderKolomPetaHilangBukanNolDiamDiam(): void
    {
        $header = array_values(array_filter(self::headerGpp(), static fn (string $h): bool => $h !== 'TJISTRI'));
        $hasil  = KolomGaji::petakanHeader($header, 'GPP');

        $this->assertFalse($hasil->valid);
        $this->assertSame('kolom_tidak_ada', $hasil->kode);
        $this->assertStringContainsString('tjistri', (string) $hasil->pesan);
        $this->assertSame(['tjistri'], $hasil->detail['kolom']);
    }

    public function testPetakanHeaderKolomGanda(): void
    {
        $header = [...array_values(array_filter(self::headerGpp(), static fn (string $h): bool => $h !== 'BERSIH')), 'Bersih', 'BERSIH'];
        $hasil  = KolomGaji::petakanHeader($header, 'GPP');

        $this->assertFalse($hasil->valid);
        $this->assertSame('kolom_ganda', $hasil->kode);
        $this->assertSame(['bersih'], $hasil->detail['kolom']);

        // Spasi ganda di header legacy menghasilkan kolom berbeda; di v2 keduanya sama → ganda.
        $tk = KolomGaji::petakanHeader(['NIP', 'BULAN', 'TAHUN', 'KOTOR', 'POTONGAN', 'BERSIH', 'PAJAK', 'TUNJ PAJAK', 'TUNJ  PAJAK', 'BERSIH 2'], 'TK');
        $this->assertSame('kolom_ganda', $tk->kode);
    }

    public function testCariSheet(): void
    {
        $nama = [' gpp ', 'Tk', 'GPP'];

        $this->assertSame(0, KolomGaji::cariSheet($nama, 'GPP'));
        $this->assertSame(1, KolomGaji::cariSheet($nama, 'TK'));
        $this->assertNull(KolomGaji::cariSheet(['Sheet1'], 'GPP'));

        $wajib = KolomGaji::cariSheetWajib($nama);
        $this->assertTrue($wajib->valid);
        $this->assertSame(['GPP' => 0, 'TK' => 1], $wajib->nilai);

        $hilang = KolomGaji::cariSheetWajib(['GPP', 'Sheet2']);
        $this->assertFalse($hilang->valid);
        $this->assertSame('sheet_tidak_ada', $hilang->kode);
        $this->assertSame(['sheet' => ['TK']], $hilang->detail);
    }
}
