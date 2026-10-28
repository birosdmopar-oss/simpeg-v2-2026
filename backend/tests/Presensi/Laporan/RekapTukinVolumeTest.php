<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Exceptions\ApiException;
use App\Libraries\Pdf\Pdf;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * Uji volume rekap Tukin (PRD 5B §7.3.7, F5B-11, AC-D08.9, pola ADR-034; hasil dicatat di ADR-035): 5.000 PNS sintetis ×
 * presensi dua periode (16 Jul – 15 Sep 2026, insertBatch di bingkai transaksi uji). Target: rekap JSON halaman 1 (100
 * baris) ≤ 5 detik; XLSX kementerian ≤ 60 detik & ≤ 256 MB; PDF satker 1.700 baris (TCPDF nyata) ≤ 30 detik & ≤ 256 MB;
 * PDF unit di atas batas → 422 sebelum kalkulasi. Memori puncak per permintaan (`memory_reset_peak_usage()`). Di-skip kecuali
 * env `RUN_VOLUME_TEST=1`.
 *
 * @internal
 */
#[Group('volume')]
final class RekapTukinVolumeTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    /** Sebaran per satker: A1 tepat di batas PDF, A2 di unit yang sama (unit di atas batas), B1 sisanya; total 5.000. */
    private const SEBARAN = ['A1' => 1700, 'A2' => 1650, 'B1' => 1650];

    public const JSON_DETIK = 5;

    public const XLSX_DETIK = 60;

    public const PDF_DETIK = 30;

    public const MB = 256;

    /** @var array<string, int> */
    private array $satker = [];

    private int $unitA;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Uji volume rekap Tukin hanya dijalankan dengan env RUN_VOLUME_TEST=1.');
        }

        $this->siapkanLaporanTukin();
        Pdf::reset();
        $this->seedVolume();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testJsonXlsxPdfDalamTarget(): void
    {
        $hasil = [];

        [$data, $detik, $mb] = $this->ukur(fn () => $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '100']));
        $this->assertSame([array_sum(self::SEBARAN), 100], [$data['total'], count($data['items'])]);
        $hasil['json'] = [$detik, $mb];
        $this->assertLessThanOrEqual(self::JSON_DETIK, $detik, 'JSON halaman 1');

        [$xlsx, $detik, $mb] = $this->ukur(fn () => $this->serviceRekapTukin()->xlsx($this->adminRekap(), ['bulan' => '2026-09']));
        $this->assertStringStartsWith('PK', $xlsx['isi']);
        $hasil['xlsx'] = [$detik, $mb];
        $this->assertLessThanOrEqual(self::XLSX_DETIK, $detik, 'XLSX kementerian');
        $this->assertLessThanOrEqual(self::MB, $mb, 'XLSX memori');

        [$pdf, $detik, $mb] = $this->ukur(fn () => $this->serviceRekapTukin()->pdf($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satker['A1']]));
        $this->assertStringStartsWith('%PDF', $pdf['isi']);
        $hasil['pdf'] = [$detik, $mb];
        $this->assertLessThanOrEqual(self::PDF_DETIK, $detik, 'PDF satker 1.700 baris');
        $this->assertLessThanOrEqual(self::MB, $mb, 'PDF memori');

        try {
            $this->serviceRekapTukin()->pdf($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => (string) $this->unitA]);
            $this->fail('unit 3.350 baris harus 422');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        fwrite(STDERR, "\n[volume rekap Tukin] " . json_encode($hasil) . "\n");
    }

    /**
     * @return array{0: mixed, 1: float, 2: float}
     */
    private function ukur(callable $aksi): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $mulai = hrtime(true);
        $hasil = $aksi();

        return [$hasil, round((hrtime(true) - $mulai) / 1e9, 2), round(memory_get_peak_usage(true) / 1048576, 1)];
    }

    private function seedVolume(): void
    {
        $this->unitA        = $this->buatUnit();
        $this->satker['A1'] = $this->buatSatker($this->unitA);
        $this->satker['A2'] = $this->buatSatker($this->unitA);
        $this->satker['B1'] = $this->buatSatker($this->buatUnit());
        $hari               = [];

        foreach (\App\Libraries\Presensi\Tukin\HariKerja::kalender('2026-07-16', '2026-09-15') as $t) {
            if (\App\Libraries\Presensi\Tukin\HariKerja::nomorHari($t) <= 5) {
                $hari[] = $t;
            }
        }

        $urut = 0;

        foreach (self::SEBARAN as $kunci => $jumlah) {
            $contoh  = $this->pegawaiRekap($this->satker[$kunci]);
            $pegawai = $this->db->table('pegawai')->where('nip', $contoh)->get()->getRowArray();
            $rmj     = $this->db->table('riwayat_mutasi_jabatan')->where('nip', $contoh)->get()->getRowArray();
            $pmj     = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $contoh)->get()->getRowArray();
            unset($rmj['id_riwayat_mutasi_jabatan']);

            for ($mulai = 1; $mulai < $jumlah; $mulai += 500) {
                $bp = $br = $bm = $bd = [];

                for ($i = $mulai; $i < min($jumlah, $mulai + 500); $i++) {
                    $nip  = '1985' . str_pad((string) (++$urut), 14, '0', STR_PAD_LEFT);
                    $bp[] = ['nip' => $nip] + $pegawai;
                    $br[] = ['nip' => $nip] + $rmj;
                    $bm[] = ['nip' => $nip] + $pmj;

                    foreach ($hari as $j => $t) {
                        $bd[] = ['nip' => $nip, 'tgl' => $t, 'jam_masuk' => $j % 7 === 0 ? '07:45:00' : '07:30:00', 'jam_pulang' => $j % 11 === 0 ? null : '16:30:00', 'sumber' => 1];
                    }
                }

                $this->db->table('pegawai')->insertBatch($bp);
                $this->db->table('riwayat_mutasi_jabatan')->insertBatch($br);
                $this->db->table('pegawai_mutasi_jabatan')->insertBatch($bm);

                foreach (array_chunk($bd, 2000) as $potong) {
                    $this->db->table('dh_online')->insertBatch($potong);
                }
            }
        }
    }
}
