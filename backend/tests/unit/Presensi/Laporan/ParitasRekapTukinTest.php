<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Laporan;

use App\Commands\PresensiParitasTukin;
use App\Libraries\Presensi\Laporan\EksporTukinXlsx;
use App\Libraries\Presensi\Laporan\ParitasRekapTukin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Uji paritas UP-1 (PRD 5B §10 M7, MAKE-043): `ParitasRekapTukin` membaca XLSX bertata letak rekap Tukin (baris data mulai
 * 9, berhenti di NIP kosong sehingga legenda & tanda tangan diabaikan) dan mengelompokkan per NIP — sama (dalam
 * toleransi), beda per kolom, hanya legacy, hanya v2, galat v2; NIP tersamar bawaan. Command `presensi:paritas-tukin`
 * mencetak ringkasan, kode keluar 0/1/2, CSV temuan, tanpa NIP penuh kecuali diminta.
 *
 * @internal
 */
final class ParitasRekapTukinTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    /** @var list<string> */
    private array $berkas = [];

    protected function tearDown(): void
    {
        foreach ($this->berkas as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    public function testBacaBerkasV2MengabaikanLegendaDanTandaTangan(): void
    {
        $baca = ParitasRekapTukin::baca($this->xlsxV2([$this->baris('199001012020011001', 1.5), $this->baris('199001012020011002', 0.0, ['message' => 'Data tidak sah'])]));

        $this->assertSame(['199001012020011001', '199001012020011002'], array_map('strval', array_keys($baca)));
        $this->assertEqualsWithDelta(1.5, $baca['199001012020011001']['angka']['TL'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $baca['199001012020011001']['angka']['total_potongan'], 1e-9);
        $this->assertNull($baca['199001012020011002']['angka'], 'baris galat v2 tanpa angka');
        $this->assertSame('Data tidak sah', $baca['199001012020011002']['keterangan']);
    }

    public function testKelompokTemuan(): void
    {
        $legacy = ParitasRekapTukin::baca($this->xlsxLegacy([
            ['199001012020011001', 1.5, 1.0],
            ['199001012020011002', 3.0, 1.0],
            ['199001012020011003', 1.0, 0.0],
            ['199001012020011005', 0.5, 0.0],
        ]));
        $v2 = ParitasRekapTukin::baca($this->xlsxV2([
            $this->baris('199001012020011001', 1.504),
            $this->baris('199001012020011002', 2.5),
            $this->baris('199001012020011004', 1.0),
            $this->baris('199001012020011005', 0.0, ['message' => 'galat']),
        ]));

        $h = ParitasRekapTukin::bandingkan($legacy, $v2);
        $this->assertSame(['legacy' => 4, 'v2' => 4, 'sama' => 1, 'beda' => 1, 'hanya_legacy' => 1, 'hanya_v2' => 1, 'galat_v2' => 1, 'galat_legacy' => 0], $h['ringkas']);

        $jenis = array_column($h['temuan'], 'jenis', 'nip');
        $this->assertSame('beda', $jenis[ParitasRekapTukin::samarkan('199001012020011002')]);
        $this->assertSame('hanya_legacy', $jenis[ParitasRekapTukin::samarkan('199001012020011003')]);
        $this->assertSame('hanya_v2', $jenis[ParitasRekapTukin::samarkan('199001012020011004')]);
        $this->assertSame('galat_v2', $jenis[ParitasRekapTukin::samarkan('199001012020011005')]);

        $beda = $h['temuan'][array_search('beda', array_column($h['temuan'], 'jenis'), true)];
        $this->assertSame(['TL', 'total_presensi', 'total_potongan'], array_keys($beda['kolom']));
        $this->assertStringNotContainsString('1990010120200110', json_encode($h, JSON_THROW_ON_ERROR), 'NIP tersamar');

        $this->assertSame('199001012020011002', ParitasRekapTukin::bandingkan($legacy, $v2, 0.01, true)['temuan'][0]['nip']);
        $this->assertSame(2, ParitasRekapTukin::bandingkan($legacy, $v2, 1.0)['ringkas']['sama'], 'toleransi lebih longgar');
    }

    public function testBerkasBukanRekapDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParitasRekapTukin::baca($this->simpan(new Spreadsheet()));
    }

    public function testCommandRingkasanKodeKeluarDanCsv(): void
    {
        $legacy = $this->xlsxLegacy([['199001012020011001', 1.5, 1.0], ['199001012020011002', 3.0, 1.0]]);
        $sama   = $this->xlsxV2([$this->baris('199001012020011001', 1.5), $this->baris('199001012020011002', 3.0)]);
        $beda   = $this->xlsxV2([$this->baris('199001012020011001', 1.5), $this->baris('199001012020011002', 2.0)]);
        $csv    = $this->berkas[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paritas_' . bin2hex(random_bytes(4)) . '.csv';

        $this->resetStreamFilterBuffer();
        $this->assertSame(0, $this->jalankan([$legacy, $sama]));
        $this->assertStringContainsString('sama: 2, beda: 0', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        $this->assertSame(1, $this->jalankan([$legacy, $beda, 'csv' => $csv]));
        $keluar = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('sama: 1, beda: 1', $keluar);
        $this->assertStringContainsString(ParitasRekapTukin::samarkan('199001012020011002') . ' beda TL legacy=3 v2=2', $keluar);
        $this->assertStringNotContainsString('199001012020011002', $keluar . (string) file_get_contents($csv));
        $this->assertStringStartsWith("nip,jenis,kolom,keterangan_legacy,keterangan_v2\n", (string) file_get_contents($csv));

        $this->resetStreamFilterBuffer();
        $this->assertSame(1, $this->jalankan([$legacy, $beda, 'nip-penuh' => null]));
        $this->assertStringContainsString('199001012020011002 beda', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        $this->assertSame(2, $this->jalankan([$legacy, 'tidak-ada.xlsx']));
        $this->assertSame(2, $this->jalankan([$legacy]));
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function jalankan(array $params): int
    {
        return (new PresensiParitasTukin(service('logger'), service('commands')))->run($params);
    }

    /**
     * @param array{message: string}|null $galat
     *
     * @return array<string, mixed>
     */
    private function baris(string $nip, float $tl, ?array $galat = null): array
    {
        return [
            'nama'           => 'Pegawai Uji', 'nip' => $nip, 'unit_es1' => 'Unit', 'unit_es2' => 'Satker',
            'pengurangan'    => $galat === null ? ['TL' => $tl, 'PSW' => 1.0, 'TK' => 0.0, 'TA' => 0.0, 'CUTI' => 0.0, 'TB' => 0.0] : null,
            'total_presensi' => $tl + 1.0, 'total_skp' => 0.0, 'total_potongan' => $tl + 1.0,
            'galat'          => $galat, 'keterangan' => '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $baris
     */
    private function xlsxV2(array $baris): string
    {
        $path = $this->berkas[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paritas_' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($path, EksporTukinXlsx::rekap('Laporan Tunjangan Kinerja', 'Kementerian', 'September 2026', $baris, ['kasus' => 'menteri', 'jabatan' => 'Menteri', 'nama' => 'Pejabat Uji', 'nip' => '']));

        return $path;
    }

    /**
     * Tiruan ekspor legacy `L_presensi.php:13370-13392`: angka ditulis `setCellValue` (bisa teks angka), NIP teks.
     *
     * @param list<array{0: string, 1: float, 2: float}> $baris NIP, TL, PSW
     */
    private function xlsxLegacy(array $baris): string
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet();
        $sheet->setCellValue('C7', 'NIP');

        foreach ($baris as $i => [$nip, $tl, $psw]) {
            $r = 9 + $i;
            $sheet->setCellValueExplicit("C{$r}", $nip, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            foreach (['F' => $tl, 'G' => $psw, 'H' => 0, 'I' => 0, 'J' => 0, 'K' => 0, 'L' => $tl + $psw, 'M' => 0, 'N' => $tl + $psw] as $kol => $nilai) {
                $sheet->setCellValue("{$kol}{$r}", (string) $nilai);
            }

            $sheet->setCellValue("O{$r}", 'Penyesuaian<br/>contoh');
        }

        $sheet->setCellValue('A' . (9 + count($baris) + 3), 'TL');

        return $this->simpan($buku);
    }

    private function simpan(Spreadsheet $buku): string
    {
        $path = $this->berkas[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paritas_' . bin2hex(random_bytes(4)) . '.xlsx';
        (new Xlsx($buku))->save($path);
        $buku->disconnectWorksheets();

        return $path;
    }
}
