<?php

declare(strict_types=1);

namespace Tests\Presensi\Rekap;

use CodeIgniter\Database\Exceptions\DatabaseException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\SlipUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * CR-183 — XLSX Rekap Uang Makan ditulis `Kepegawaian\Pegawai\EksporTabel` (bukan PhpSpreadsheet), isinya tetap: sheet
 * "Rekap Uang Makan", header `NIP | TGL_ABSEN` tanpa judul, satu baris per hari dibayar dengan urutan yang sama dengan
 * JSON (populasi lalu tanggal) untuk varian DH maupun IL, dan NIP 18 digit serta tanggal tetap sel teks. September 2026
 * (1 Sep = Selasa).
 *
 * @internal
 */
final class RekapUangMakanXlsxTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use SlipUjiTrait;
    use TukinDataUjiTrait;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->satker = $this->buatSatker($this->buatUnit());
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testIsiSheetJudulHeaderDanSelTeks(): void
    {
        $a = $this->pegawaiRekap($this->satker, ['nip' => '199001012020121002']);
        $b = $this->pegawaiRekap($this->satker, ['nip' => '199001012020121004']);
        $this->hadir($a, '2026-09-03');
        $this->hadir($a, '2026-09-01');
        $this->hadir($b, '2026-09-02');

        $query  = ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satker, 'per_page' => '100'];
        $json   = $this->serviceUangMakan()->daftar($this->adminRekap(), $query)['items'];
        $berkas = $this->serviceUangMakan()->xlsx($this->adminRekap(), $query);
        $sheet  = $this->sheet($berkas['isi']);

        $this->assertSame('rekap_uang_makan_sat_' . $this->satker . '_2026-09.xlsx', $berkas['nama']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $berkas['mime']);
        $this->assertSame('Rekap Uang Makan', $sheet->getTitle());
        $this->assertSame(
            [['NIP', 'TGL_ABSEN'], ...array_map(static fn (array $r): array => [$r['nip'], $r['tgl_absen']], $json)],
            $sheet->toArray(null, false, false, false),
            'header lalu baris yang sama dan seurut dengan JSON',
        );
        $this->assertCount(3, $json);
        $this->assertSame(['2026-09-01', '2026-09-03'], array_column(array_filter($json, static fn (array $r): bool => $r['nip'] === $a), 'tgl_absen'));

        // NIP 18 digit dan tanggal tetap teks (bukan angka ilmiah / serial tanggal), header juga teks.
        foreach (['A1', 'B1', 'A2', 'B2', 'A4', 'B4'] as $alamat) {
            $this->assertContains($sheet->getCell($alamat)->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], $alamat);
        }

        $this->assertSame('199001012020121002', (string) $sheet->getCell('A2')->getValue());
    }

    public function testTanpaHariDibayarHanyaHeader(): void
    {
        $this->pegawaiRekap($this->satker);

        $berkas = $this->serviceUangMakan()->xlsx($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satker]);

        $this->assertSame([['NIP', 'TGL_ABSEN']], $this->sheet($berkas['isi'])->toArray(null, false, false, false));
    }

    /**
     * Varian IL: XLSX diiterasi atas populasi yang sama dengan JSON, jadi jumlah dan urutan barisnya sama. Catatan:
     * `pegawai_mutasi_jabatan` ber-PRIMARY KEY `nip`, jadi join populasi IL (`PopulasiRekap::il()`) tidak bisa
     * menghasilkan NIP ganda di skema v2 (dijaga di sini: sisipan baris kedua ditolak database).
     */
    public function testVarianIlSamaDenganJsonDanPopulasiTanpaNipGanda(): void
    {
        $x = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'nip' => '199001012020121005'], ['jenis_jabatan' => 2]);
        $y = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'nip' => '199001012020121003'], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($x, ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($y, ['jenis_jabatan' => 2]);
        $this->hadir($x, '2026-09-02');
        $this->hadir($y, '2026-09-03');
        $this->hadir($y, '2026-09-02');

        $json  = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '100'], true);
        $baris = array_map(static fn (array $r): array => [$r['nip'], $r['tgl_absen']], $json['items']);

        $this->assertEqualsCanonicalizing([[$x, '2026-09-02'], [$y, '2026-09-02'], [$y, '2026-09-03']], $baris, 'IL: satu baris per hari dibayar');

        $berkas = $this->serviceUangMakan()->xlsx($this->adminRekap(), ['bulan' => '2026-09'], true);

        $this->assertSame('rekap_uang_makan_il_2026-09.xlsx', $berkas['nama']);
        $this->assertSame([['NIP', 'TGL_ABSEN'], ...$baris], $this->sheet($berkas['isi'])->toArray(null, false, false, false));

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $x)->get()->getRowArray();
        $this->assertIsArray($pmj);
        $this->expectException(DatabaseException::class);
        $this->db->table('pegawai_mutasi_jabatan')->insert($pmj);
    }

    private function sheet(string $isi): Worksheet
    {
        $berkas = (string) tempnam(sys_get_temp_dir(), 'rum');
        file_put_contents($berkas, $isi);

        try {
            return IOFactory::load($berkas)->getActiveSheet();
        } finally {
            @unlink($berkas);
        }
    }
}
