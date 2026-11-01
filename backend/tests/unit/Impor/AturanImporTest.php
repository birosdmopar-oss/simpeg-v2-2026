<?php

declare(strict_types=1);

namespace Tests\Unit\Impor;

use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PenjagaTujuan;
use App\Libraries\Impor\Transformasi;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-056 — aturan murni alat impor: transformasi nilai (DBV-011), berkas keputusan (GAP-6), penjaga tujuan.
 *
 * @internal
 */
final class AturanImporTest extends CIUnitTestCase
{
    public function testStripslashesSamaDenganPhp(): void
    {
        $this->assertSame("O'Brien \\ \"x\" d", Transformasi::stripslashes("O\\'Brien \\\\ \\\"x\\\" \\d"));
        $this->assertNull(Transformasi::stripslashes(null));
    }

    public function testNamaDinormalisasiSepertiAplikasi(): void
    {
        $this->assertSame('Biro Umum', Transformasi::nama("  Biro\t\n  Umum "));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function rujukan(): iterable
    {
        yield 'nol angka' => [0, null];
        yield 'nol teks' => ['0', null];
        yield 'kosong' => ['', null];
        yield 'spasi' => [' ', null];
        yield 'null' => [null, null];
        yield 'id sah' => [12, 12];
        yield 'id teks' => ['7', '7'];
    }

    #[DataProvider('rujukan')]
    public function testRujukanNolMenjadiNull(mixed $masuk, mixed $harap): void
    {
        $this->assertSame($harap, Transformasi::rujukan($masuk));
    }

    public function testR1aDatetimeWibDikurangiTujuhJam(): void
    {
        $this->assertSame('2026-08-15 17:30:00', Transformasi::waktuUtc('2026-08-16 00:30:00', 'datetime', Transformasi::ZONA_WIB));
    }

    public function testR1aDatetimeUtcTidakDiubah(): void
    {
        $this->assertSame('2026-08-16 00:30:00', Transformasi::waktuUtc('2026-08-16 00:30:00', 'datetime', Transformasi::ZONA_UTC));
    }

    public function testR1bTimestampDanR2DateTidakDikonversi(): void
    {
        $this->assertSame('2026-08-16 00:30:00', Transformasi::waktuUtc('2026-08-16 00:30:00', 'timestamp', Transformasi::ZONA_WIB));
        $this->assertSame('2026-08-16', Transformasi::waktuUtc('2026-08-16', 'date', Transformasi::ZONA_WIB));
    }

    public function testR1aFormatRusakGagal(): void
    {
        $this->expectException(ImporGagal::class);
        Transformasi::waktuUtc('16-08-2026', 'datetime', Transformasi::ZONA_WIB);
    }

    public function testR3TanggalNolDikenali(): void
    {
        $this->assertTrue(Transformasi::tanggalNol('0000-00-00 00:00:00'));
        $this->assertTrue(Transformasi::tanggalNol('0000-00-00'));
        $this->assertTrue(Transformasi::tanggalNol(''));
        $this->assertFalse(Transformasi::tanggalNol('2026-01-01'));
        $this->assertFalse(Transformasi::tanggalNol(null));
    }

    public function testStatusDalamDomainApaAdanya(): void
    {
        $this->assertSame(2, Keputusan::kosong()->status('unit', '2', [1, 2, 10]));
    }

    public function testStatusLuarDomainTanpaKeputusanGagal(): void
    {
        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage("Status unit '3'");
        Keputusan::kosong()->status('unit', 3, [1, 2, 10]);
    }

    public function testStatusLuarDomainDanNullDipetakan(): void
    {
        $k = Keputusan::dariArray(['status' => ['unit' => ['3' => 2, 'null' => 1]]]);

        $this->assertSame(2, $k->status('unit', 3, [1, 2, 10]));
        $this->assertSame(1, $k->status('unit', null, [1, 2, 10]));
    }

    public function testNilaiDanTanggalNolDariKeputusan(): void
    {
        $k = Keputusan::dariArray([
            'nilai'       => ['hari_libur.id_jenis_libur' => ['null' => 1]],
            'tanggal_nol' => ['unit.created_at' => '2020-01-01 00:00:00'],
        ]);

        $this->assertSame(['ada' => true, 'nilai' => 1], $k->nilai('hari_libur', 'id_jenis_libur', null));
        $this->assertSame(['ada' => false, 'nilai' => 2], $k->nilai('hari_libur', 'id_jenis_libur', 2));
        $this->assertSame('2020-01-01 00:00:00', $k->tanggalNol('unit', 'created_at'));
        $this->assertNull($k->tanggalNol('unit', 'updated_at'));
    }

    public function testBagianKeputusanTakDikenalDitolak(): void
    {
        $this->expectException(ImporGagal::class);
        Keputusan::dariArray(['nip' => []]);
    }

    public function testBerkasKeputusanContohSah(): void
    {
        $k = Keputusan::dariBerkas(ROOTPATH . '../docs/up1/keputusan-impor.contoh.json');

        $this->assertSame(1, $k->nilai('hari_libur', 'id_jenis_libur', null)['nilai']);
    }

    public function testBerkasKeputusanBukanJsonGagal(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'kep');
        file_put_contents($f, '{rusak');

        try {
            $this->expectException(ImporGagal::class);
            Keputusan::dariBerkas($f);
        } finally {
            unlink($f);
        }
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function tujuanDitolak(): iterable
    {
        yield 'dev bersama' => ['simpeg_v2', 'salinan', 'simpeg_v2'];
        yield 'test bersama' => ['SIMPEG_V2_TESTING', 'salinan', 'SIMPEG_V2_TESTING'];
        yield 'legacy produksi' => ['simpeg01', 'salinan', 'simpeg01'];
        yield 'awalan prod' => ['prod_simpeg', 'salinan', 'prod_simpeg'];
        yield 'sumber = tujuan' => ['simpeg_v2_t_up1', 'simpeg_v2_t_up1', 'simpeg_v2_t_up1'];
        yield 'tanpa konfirmasi' => ['simpeg_v2_t_up1', 'salinan', null];
        yield 'konfirmasi beda' => ['simpeg_v2_t_up1', 'salinan', 'simpeg_v2_t_UP1'];
        yield 'kosong' => ['', 'salinan', ''];
    }

    #[DataProvider('tujuanDitolak')]
    public function testPenjagaMenolakTujuan(string $tujuan, string $sumber, ?string $konfirmasi): void
    {
        $this->expectException(ImporGagal::class);
        PenjagaTujuan::periksa($tujuan, $sumber, $konfirmasi, ['simpeg_v2', 'simpeg_v2_testing', 'simpeg01']);
    }

    public function testPenjagaMenerimaScratchBerkonfirmasi(): void
    {
        PenjagaTujuan::periksa('simpeg_v2_t_up1', 'up1_legacy_salinan', 'simpeg_v2_t_up1', ['simpeg_v2']);
        $this->addToAssertionCount(1);
    }
}
