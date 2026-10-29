<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.4 varian IL (MAKE-041, F5B-6): populasi Instansi Lain (`jenis_jabatan = 2`, dibuat ≤ awal periode), tanpa
 * filter unit/jenis pegawai, penanda tangan menteri dari `web_config`, judul & nama berkas IL; daftar pegawai IL.
 *
 * @internal
 */
final class RekapTukinIlTest extends DatabaseTestCase
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

    public function testPopulasiIlDanPenandaTanganMenteri(): void
    {
        $dh = $this->pegawaiRekap($this->buatSatker($this->buatUnit()));
        $il = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($il, ['jenis_jabatan' => 2]);
        $baru = $this->buatPegawai(['created_at' => '2026-08-20 00:00:00', 'id_jenis_pegawai' => 2], ['jenis_jabatan' => 2]);
        $this->aturWebConfig(['jabatan_menteri_full' => 'Menteri Uji', 'nama_menteri' => 'Nama Menteri Uji']);

        $data = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'jenis_pegawai' => ['99']], true);
        $this->assertSame([$il], array_column($data['items'], 'nip'), 'IL: tanpa filter jenis; dibuat setelah awal periode tidak ikut');
        $this->assertNotContains($dh, array_column($data['items'], 'nip'));
        $this->assertNotContains($baru, array_column($data['items'], 'nip'));

        $berkas = $this->serviceRekapTukin()->xlsx($this->adminRekap(), ['bulan' => '2026-09'], true);
        $this->assertSame('laporan_tukin_il_2026-09.xlsx', $berkas['nama']);
        $tmp = tempnam(sys_get_temp_dir(), 'ril');
        file_put_contents((string) $tmp, $berkas['isi']);
        $s = IOFactory::load((string) $tmp)->getActiveSheet();
        @unlink((string) $tmp);

        $this->assertStringStartsWith('LAPORAN TUNJANGAN KINERJA INSTANSI LAIN', (string) $s->getCell('A1')->getValue());
        $this->assertSame('Menteri Uji', $s->getCell('M12')->getValue());

        $daftar = $this->serviceDaftarTukin()->daftar($this->adminRekap(), [], true);
        $this->assertSame([$il, $baru], array_values(array_intersect([$il, $baru], array_column($daftar['items'], 'nip'))), 'daftar IL: snapshot jenis 2 aktif');
        $this->assertNull($daftar['lingkup']);
    }

    /**
     * CR-083, keputusan Horii 30-10 [K]: `laporan_tukin_us_skp_il` (`L_presensi.php:13494-15452`) tidak punya blok pegawai
     * baru (`isPegBaru` hanya di rekap DH :12702-12743), jadi di IL `tmtMasuk` null dan `lewatiLoopHarian` false:
     * PPPK IL tanpa TMT (jabatan jenis 2 saja, tanpa `riwayat_kp`) dan PPPK ber-TMT di tengah periode dihitung normal (tanpa
     * presensi → 22 hari kerja TK). Pembanding: PPPK tanpa TMT yang sama di laporan per pegawai (jalur DH) melewati loop.
     * CPNS ber-TMT di tengah periode tidak terbentuk di IL (pangkat dipilih `tmtsk` ≤ awal periode, populasi IL dibuat ≤
     * awal).
     */
    public function testIlTanpaAturanPegawaiBaru(): void
    {
        $tanpaTmt = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 6], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($tanpaTmt, ['jenis_jabatan' => 2]);
        $tengah = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 6], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($tengah, ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($tengah, ['jenis_jabatan' => 1, 'tmtsk' => '2026-08-25', 'gol_pppk' => 'V']);

        $baris = array_column($this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '100'], true)['items'], null, 'nip');

        foreach ([$tanpaTmt => 'PPPK tanpa TMT', $tengah => 'PPPK TMT 25-08'] as $nip => $kasus) {
            $this->assertNull($baris[$nip]['galat'], $kasus);
            $this->assertSame(['13.20', '13.20', '32.00', '45.20'], [$baris[$nip]['pengurangan']['TK'], $baris[$nip]['total_presensi'], $baris[$nip]['total_skp'], $baris[$nip]['total_potongan']], "{$kasus}: 22 hari kerja TK");
        }

        $dh = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $tanpaTmt, ['bulan' => '2026-09']);
        $this->assertSame(['0.00', '32.00'], [$dh['total_presensi'], $dh['total_potongan']], 'pembanding jalur DH: loop dilewati');
    }
}
