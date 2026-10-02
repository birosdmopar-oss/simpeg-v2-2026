<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\BarisImpor;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (I-5..I-7, P-3, P-4) — sel kunci dan nominal satu baris impor dari nilai mentah sel. NIP fiktif berpola.
 *
 * @internal
 */
final class BarisImporTest extends CIUnitTestCase
{
    private const NIP = '199001012020011001';

    /**
     * Sel lengkap sheet GPP: kunci + 22 field (nominal sintetis).
     *
     * @param array<string, mixed> $timpa
     *
     * @return array<string, mixed>
     */
    public static function selGpp(array $timpa = []): array
    {
        $sel = ['nip' => self::NIP, 'bulan' => 10, 'tahun' => 2026];

        foreach (array_keys(KolomGaji::GPP) as $field) {
            $sel[$field] = null;
        }

        return array_merge($sel, ['gjpokok' => 3_000_000, 'bersih_gpp' => 3_000_000], $timpa);
    }

    public function testBatasNipSamaDenganPengguna(): void
    {
        $this->assertSame(PenggunaModel::NIP_MAX_DIGITS, BarisImpor::NIP_MAKS_DIGIT);
    }

    public function testNipTeks(): void
    {
        $this->assertSame(self::NIP, BarisImpor::nip(self::NIP)->nilai);
        $this->assertSame(self::NIP, BarisImpor::nip(' 1990 0101 2020 01 1 001 ')->nilai);
        $this->assertSame(self::NIP, BarisImpor::nip("\u{00A0}" . self::NIP)->nilai);
        $this->assertSame('1234567890123456', BarisImpor::nip('1234567890123456')->nilai);

        foreach (['', '  ', null] as $kosong) {
            $hasil = BarisImpor::nip($kosong);
            $this->assertTrue($hasil->valid);
            $this->assertNull($hasil->nilai);
        }
    }

    public function testNipTidakValid(): void
    {
        foreach (['JUMLAH', '1990010120200110011', '1990-0101', 'NIP 1990', "1990\t0101", true] as $nilai) {
            $this->assertSame('nip_tidak_valid', BarisImpor::nip($nilai)->kode, var_export($nilai, true));
        }

        foreach ([1.99001012020011E17, 199001012020011001, 0] as $angka) {
            $hasil = BarisImpor::nip($angka);
            $this->assertSame('nip_bukan_teks', $hasil->kode, var_export($angka, true));
        }
    }

    public function testBulan(): void
    {
        foreach ([8, '8', '08', 8.0, 'Agustus', ' agustus ', 'AGUSTUS', "Agustus\u{00A0}"] as $nilai) {
            $hasil = BarisImpor::bulan($nilai);
            $this->assertTrue($hasil->valid, var_export($nilai, true));
            $this->assertSame(8, $hasil->nilai);
        }

        $this->assertSame(12, BarisImpor::bulan('Desember')->nilai);

        // Batas angka 1 dan 12 lewat jalur angka (bukan nama bulan).
        foreach ([1 => [1, '1', '01', 1.0], 12 => [12, '12', 12.0]] as $harapan => $daftar) {
            foreach ($daftar as $nilai) {
                $this->assertSame($harapan, BarisImpor::bulan($nilai)->nilai, var_export($nilai, true));
            }
        }

        foreach (['Agu', 'August', '8.7', 8.5, '1e1', '13', '0', '', null, 0, -1, true, '008', NAN] as $nilai) {
            $this->assertSame('bulan_tidak_valid', BarisImpor::bulan($nilai)->kode, var_export($nilai, true));
        }
    }

    public function testTahun(): void
    {
        foreach ([2026, 2026.0, '2026', ' 2026 '] as $nilai) {
            $hasil = BarisImpor::tahun($nilai);
            $this->assertTrue($hasil->valid, var_export($nilai, true));
            $this->assertSame(2026, $hasil->nilai);
        }

        // Batas inklusif 1900 dan 2100 (HariLiburRules::YEAR_MIN/MAX).
        foreach ([1900, '1900', 1900.0, 2100, '2100', 2100.0] as $nilai) {
            $this->assertSame((int) $nilai, BarisImpor::tahun($nilai)->nilai, var_export($nilai, true));
        }

        foreach (['2026abc', '2,026', 2026.5, '1899', '2101', '', null, 1899, 2101, '26', INF] as $nilai) {
            $this->assertSame('tahun_tidak_valid', BarisImpor::tahun($nilai)->kode, var_export($nilai, true));
        }
    }

    public function testUraiBarisValid(): void
    {
        $baris = BarisImpor::urai(self::selGpp(['tjistri' => '300.000', 'nama' => ' Pegawai Contoh ']), 'GPP', 2);

        $this->assertNotNull($baris);
        $this->assertTrue($baris->valid());
        $this->assertSame('GPP', $baris->sheet);
        $this->assertSame(2, $baris->nomorBaris);
        $this->assertSame(self::NIP, $baris->nip);
        $this->assertSame('2026-10', $baris->periode?->kunci());
        $this->assertSame(self::NIP . '|2026-10', $baris->kunci());
        $this->assertSame('Pegawai Contoh', $baris->nama);
        $this->assertCount(22, $baris->nominal);
        $this->assertSame(300_000_000, $baris->nominal['gjpokok']);
        $this->assertSame(30_000_000, $baris->nominal['tjistri']);
        $this->assertSame(0, $baris->nominal['tjlain']);
        $this->assertSame(300_000_000, $baris->nominal['bersih_gpp']);
    }

    public function testUraiNipKosongDilewati(): void
    {
        $this->assertNull(BarisImpor::urai(self::selGpp(['nip' => '  ']), 'GPP', 7));
        $this->assertNull(BarisImpor::urai(self::selGpp(['nip' => null]), 'GPP', 7));
    }

    public function testUraiTanpaKolomNama(): void
    {
        $baris = BarisImpor::urai(self::selGpp(), 'GPP', 2);

        $this->assertNull($baris?->nama);

        $kosong = BarisImpor::urai(self::selGpp(['nama' => null]), 'GPP', 2);
        $this->assertSame('', $kosong?->nama);
    }

    public function testUraiNominalWajibDanKosong(): void
    {
        $baris = BarisImpor::urai(self::selGpp(['gjpokok' => '', 'tjlain' => null]), 'GPP', 3);

        $this->assertNotNull($baris);
        $this->assertSame(['nominal_wajib'], array_column($baris->galat, 'kode'));
        $this->assertSame('Sheet GPP baris 3: kolom gjpokok wajib diisi, tidak boleh kosong.', $baris->galat[0]['pesan']);
        $this->assertSame(0, $baris->nominal['tjlain']);

        // Kolom `bersih` (sumber) → `bersih_gpp` (field) juga wajib terisi.
        $tanpaBersih = BarisImpor::urai(self::selGpp(['bersih_gpp' => '']), 'GPP', 4);
        $this->assertStringContainsString('kolom bersih wajib', $tanpaBersih?->galat[0]['pesan'] ?? '');
    }

    public function testUraiNominalTidakValidMenyebutKolomSheetBaris(): void
    {
        $baris = BarisImpor::urai(self::selGpp(['tjistri' => 'abc']), 'GPP', 3);

        $this->assertNotNull($baris);
        $this->assertSame('nominal_tidak_valid', $baris->galat[0]['kode']);
        $pesan = $baris->galat[0]['pesan'];
        $this->assertStringContainsString('tjistri', $pesan);
        $this->assertStringContainsString('GPP', $pesan);
        $this->assertStringContainsString('baris 3', $pesan);
        $this->assertStringContainsString("'abc'", $pesan);
        $this->assertSame(0, $baris->nominal['tjistri']);
    }

    public function testUraiNominalNegatifDitolakInterim(): void
    {
        $baris = BarisImpor::urai(self::selGpp(['potlain' => '-1.000']), 'GPP', 5);

        $this->assertSame(['nominal_negatif'], array_column($baris->galat ?? [], 'kode'));
        $this->assertSame(0, $baris?->nominal['potlain']);
    }

    public function testUraiMengumpulkanSemuaGalat(): void
    {
        $baris = BarisImpor::urai(self::selGpp(['nip' => 199001012020011001, 'bulan' => 'Agu', 'tahun' => '2026abc', 'tjanak' => '1.5']), 'GPP', 9);

        $this->assertNotNull($baris);
        $this->assertNull($baris->nip);
        $this->assertNull($baris->periode);
        $this->assertNull($baris->kunci());
        $this->assertSame(['nip_bukan_teks', 'bulan_tidak_valid', 'tahun_tidak_valid', 'nominal_tidak_valid'], array_column($baris->galat, 'kode'));
        $this->assertStringStartsWith('Sheet GPP baris 9: ', $baris->galat[0]['pesan']);
    }

    public function testUraiSheetTk(): void
    {
        $sel   = ['nip' => self::NIP, 'bulan' => 'Oktober', 'tahun' => '2026', 'kotor' => 4_000_000, 'potongan' => 200_000.0, 'bersih_tk' => '3.800.000', 'pajak' => null, 'tunj_pajak' => '', 'bersih_2' => 3_800_000];
        $baris = BarisImpor::urai($sel, 'TK', 2);

        $this->assertNotNull($baris);
        $this->assertTrue($baris->valid());
        $this->assertSame(['kotor', 'potongan', 'bersih_tk', 'pajak', 'tunj_pajak', 'bersih_2'], array_keys($baris->nominal));
        $this->assertSame(380_000_000, $baris->nominal['bersih_tk']);
        $this->assertSame(0, $baris->nominal['pajak']);
    }
}
