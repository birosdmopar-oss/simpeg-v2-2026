<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Libraries\Presensi\Tukin\HariKerja;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\SlipUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;
use ZipArchive;

/**
 * Uji volume ekspor XLSX Rekap Uang Makan (CR-183, pola {@see RekapTukinVolumeTest}; target ADR-035): PNS sintetis ×
 * presensi setiap hari kerja September 2026 (22 hari; insertBatch di bingkai transaksi uji), lingkup kementerian.
 * Bawaan 5.000 pegawai = 110.000 baris; env `VOLUME_PEGAWAI` mengganti jumlahnya. Target: XLSX ≤ 60 detik dan ≤ 256 MB.
 * Memori puncak per permintaan (`memory_reset_peak_usage()`). Di-skip kecuali env `RUN_VOLUME_TEST=1`.
 *
 * Terukur CR-183: 5.000 pegawai 3,1 detik / 160 MB (sebelumnya 14,6 detik / 294 MB lewat PhpSpreadsheet).
 * `VOLUME_PEGAWAI=15000` (330.000 baris) DIKETAHUI melewati target memori (9,4 detik / 396 MB), jadi test ini merah di
 * sana dan itu memang diharapkan sampai penulis sheet bertahap dikerjakan (kandidat CR lanjutan; README Presensi).
 *
 * @internal
 */
#[Group('volume')]
final class RekapUangMakanVolumeTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use SlipUjiTrait;
    use TukinDataUjiTrait;

    public const XLSX_DETIK = 60;

    public const MB = 256;

    private int $total = 5000;

    /** @var list<string> */
    private array $hari = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Uji volume rekap uang makan hanya dijalankan dengan env RUN_VOLUME_TEST=1.');
        }

        $jumlah = (int) getenv('VOLUME_PEGAWAI');

        if ($jumlah > 0) {
            $this->total = $jumlah;
        }

        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->seedVolume();
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testXlsxKementerianDalamTarget(): void
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $mulai  = hrtime(true);
        $berkas = $this->serviceUangMakan()->xlsx($this->adminRekap(), ['bulan' => '2026-09']);
        $detik  = round((hrtime(true) - $mulai) / 1e9, 2);
        $mb     = round(memory_get_peak_usage(true) / 1048576, 1);
        $baris  = $this->total * count($this->hari);

        fwrite(STDERR, sprintf("\n[volume rekap uang makan] %d pegawai, %d baris: xlsx %.2f detik, puncak %.1f MB, berkas %.1f MB\n", $this->total, $baris, $detik, $mb, strlen($berkas['isi']) / 1048576));

        $this->assertStringStartsWith('PK', $berkas['isi']);
        $this->assertSame('rekap_uang_makan_0_2026-09.xlsx', $berkas['nama']);
        $this->assertSame($baris + 1, $this->jumlahBaris($berkas['isi']), 'header + satu baris per hari dibayar');
        $this->assertLessThanOrEqual(self::XLSX_DETIK, $detik, 'XLSX kementerian');
        $this->assertLessThanOrEqual(self::MB, $mb, 'XLSX memori');
    }

    /**
     * Jumlah `<row>` sheet pertama, dihitung dari XML mentah sesudah pengukuran (memuat ratusan ribu baris lewat pembaca
     * terlalu lama).
     */
    private function jumlahBaris(string $isi): int
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rum');
        file_put_contents($path, $isi);
        $zip = new ZipArchive();

        try {
            $this->assertTrue($zip->open($path));

            return (int) preg_match_all('/<row[ >]/', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function seedVolume(): void
    {
        foreach (HariKerja::kalender('2026-09-01', '2026-09-30') as $t) {
            if (HariKerja::nomorHari($t) <= 5) {
                $this->hari[] = $t;
            }
        }

        $satker  = $this->buatSatker($this->buatUnit());
        $contoh  = $this->pegawaiRekap($satker);
        $pegawai = $this->db->table('pegawai')->where('nip', $contoh)->get()->getRowArray();
        $rmj     = $this->db->table('riwayat_mutasi_jabatan')->where('nip', $contoh)->get()->getRowArray();
        $pmj     = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $contoh)->get()->getRowArray();
        $this->assertIsArray($pegawai);
        $this->assertIsArray($rmj);
        unset($rmj['id_riwayat_mutasi_jabatan']);

        foreach ($this->hari as $t) {
            $this->hadir($contoh, $t);
        }

        for ($mulai = 1; $mulai < $this->total; $mulai += 500) {
            $bp = $br = $bm = $bd = [];

            for ($i = $mulai; $i < min($this->total, $mulai + 500); $i++) {
                $nip  = '1985' . str_pad((string) $i, 14, '0', STR_PAD_LEFT);
                $bp[] = ['nip' => $nip] + $pegawai;
                $br[] = ['nip' => $nip] + $rmj;

                if (is_array($pmj)) {
                    $bm[] = ['nip' => $nip] + $pmj;
                }

                foreach ($this->hari as $t) {
                    $bd[] = ['nip' => $nip, 'tgl' => $t, 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:00:00', 'sumber' => 1];
                }
            }

            $this->db->table('pegawai')->insertBatch($bp);
            $this->db->table('riwayat_mutasi_jabatan')->insertBatch($br);

            if ($bm !== []) {
                $this->db->table('pegawai_mutasi_jabatan')->insertBatch($bm);
            }

            foreach (array_chunk($bd, 2000) as $potong) {
                $this->db->table('dh_online')->insertBatch($potong);
            }
        }
    }
}
