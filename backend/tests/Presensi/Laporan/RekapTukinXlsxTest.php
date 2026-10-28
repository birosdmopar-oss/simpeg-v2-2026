<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.4 AC-D08.5/.10 (MAKE-041): XLSX rekap dibaca ulang PhpSpreadsheet — judul A1:Q6, header baris 7–8 persis,
 * data mulai baris 9 (angka sebagai angka, NIP teks), legenda, blok penanda tangan selalu ada di kolom M (+2/+9/+10), nama
 * berkas & content type; baris galat → KETERANGAN = pesan.
 *
 * @internal
 */
final class RekapTukinXlsxTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testTataLetakDanBlokPenandaTangan(): void
    {
        $satker = $this->buatSatker($this->buatUnit());
        $nip    = $this->pegawaiRekap($satker, ['nip' => '199001012020121009']);
        $this->cutiRekap($nip, '2026-09-02', '2026-09-03');
        $this->aturWebConfig(['jabatan_menteri_full' => 'Menteri Uji', 'nama_menteri' => 'Nama Menteri Uji']);

        $berkas = $this->serviceRekapTukin()->xlsx($this->adminRekap(), ['bulan' => '2026-09']);
        $this->assertSame(['laporan_tukin_0_2026-09.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], [$berkas['nama'], $berkas['mime']]);

        $s = $this->sheet($berkas['isi']);
        $this->assertSame('Laporan Tunjangan Kinerja', $s->getTitle());
        $this->assertSame("LAPORAN TUNJANGAN KINERJA PEGAWAI NEGERI SIPIL\nKEMENTERIAN\nPeriode: September 2026", $s->getCell('A1')->getValue());
        $this->assertArrayHasKey('A1:Q6', $s->getMergeCells());

        $this->assertSame(['NO', 'NAMA', 'NIP', 'UNIT ES.I', 'UNIT ES.II', 'JUMLAH PENGURANGAN (%)', null, null, null, null, null, 'TOTAL PENGURANGAN PRESENSI (%)', 'TOTAL PENGURANGAN SKP (%)', 'TOTAL POTONGAN (%) (PRESENSI + SKP)', 'KETERANGAN'], $s->rangeToArray('A7:O7')[0]);
        $this->assertSame(['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'], $s->rangeToArray('F8:K8')[0]);
        $this->assertArrayHasKey('F7:K7', $s->getMergeCells());

        $this->assertSame('199001012020121009', $s->getCell('C9')->getValue());
        $this->assertSame(12.0, (float) $s->getCell('H9')->getValue(), 'TK 20 hari × 0,6 (2 hari cuti)');
        $this->assertIsNumeric($s->getCell('N9')->getValue());
        $this->assertStringContainsString('(02 September 2026 s.d 03 September 2026)', (string) $s->getCell('O9')->getValue());

        // Baris data terakhir = 9 → r = 10; legenda A12..A16; penanda tangan M12 / M19 / M20.
        $this->assertSame(['Note', 'TL', 'PSW', 'TK', 'TA'], [$s->getCell('A12')->getValue(), $s->getCell('A13')->getValue(), $s->getCell('A14')->getValue(), $s->getCell('A15')->getValue(), $s->getCell('A16')->getValue()]);
        $this->assertSame(': Terlambat', $s->getCell('B13')->getValue());
        $this->assertSame(['Menteri Uji', 'Nama Menteri Uji', ''], [$s->getCell('M12')->getValue(), $s->getCell('M19')->getValue(), (string) $s->getCell('M20')->getValue()]);
    }

    public function testBlokPenandaTanganSelaluAdaDanBarisGalat(): void
    {
        $this->pegawaiRekap($this->buatSatker($this->buatUnit()));
        $this->db->table('web_config')->where('config_name', 'TK')->delete();
        $this->resetWebConfig();

        $s = $this->sheet($this->serviceRekapTukin()->xlsx($this->adminRekap(), ['bulan' => '2026-09'])['isi']);

        $this->assertNull($s->getCell('N9')->getValue(), 'galat: angka kosong');
        $this->assertNotSame('', (string) $s->getCell('O9')->getValue());
        $this->assertSame('Data menteri belum diatur di Web Config', $s->getCell('M12')->getValue(), 'blok tetap dicetak');
    }

    private function sheet(string $isi): Worksheet
    {
        $berkas = tempnam(sys_get_temp_dir(), 'rtk');
        file_put_contents((string) $berkas, $isi);

        try {
            return IOFactory::load((string) $berkas)->getActiveSheet();
        } finally {
            @unlink((string) $berkas);
        }
    }
}
