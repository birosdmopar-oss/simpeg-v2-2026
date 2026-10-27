<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Notifikasi;

use App\Libraries\Layanan\Notifikasi\TemplatNotifikasi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.6.2) — templat notifikasi cuti murni: judul dan isi sesuai tabel PRD, typo "anda anda"
 * (N7) tidak ada, alasan hanya pada tolak.
 *
 * @internal
 */
final class TemplatNotifikasiTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function cuti(): array
    {
        return [
            'nama'                 => 'Budi', 'nip' => '198001012005011001', 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-11-04', 'tgl_akhir' => '2026-11-06',
            'nama_atasan_langsung' => 'Ani', 'nama_yang_menyetujui' => 'Citra',
        ];
    }

    public function testDiajukan(): void
    {
        $t = TemplatNotifikasi::diajukan($this->cuti());

        $this->assertSame('Permohonan Cuti Online Baru', $t['judul']);
        $this->assertStringStartsWith('Budi telah mengajukan cuti (Cuti Tahunan) untuk tanggal ', $t['isi']);
        $this->assertStringContainsString(' s/d ', $t['isi']);
    }

    public function testTembusanDiajukanMemakaiSdTitik(): void
    {
        $t = TemplatNotifikasi::tembusanDiajukan($this->cuti());

        $this->assertSame('Tembusan Cuti Online', $t['judul']);
        $this->assertStringContainsString('melakukan pengajuan Cuti Tahunan dari tanggal ', $t['isi']);
        $this->assertStringContainsString(' s.d ', $t['isi']);
    }

    public function testDiubah(): void
    {
        $t = TemplatNotifikasi::diubah($this->cuti());

        $this->assertSame('Perubahan Cuti Online', $t['judul']);
        $this->assertStringEndsWith('oleh Budi / 198001012005011001', $t['isi']);
    }

    public function testSedangDiprosesMenyebutAlDanPyb(): void
    {
        $t = TemplatNotifikasi::sedangDiproses($this->cuti());

        $this->assertSame('Cuti Online Sedang Diproses', $t['judul']);
        $this->assertStringContainsString('disetujui atasan langsung Ani dan menunggu persetujuan Citra.', $t['isi']);
        $this->assertStringNotContainsString('anda anda', $t['isi']);
    }

    public function testKeputusanJudulDanAlasanHanyaUntukTolak(): void
    {
        $setuju = TemplatNotifikasi::keputusan($this->cuti(), 1, 'Citra', 'diabaikan');
        $tolak  = TemplatNotifikasi::keputusan($this->cuti(), 2, 'Citra', 'Kuota penuh');
        $tunda  = TemplatNotifikasi::keputusan($this->cuti(), 4, 'Ani', 'diabaikan');

        $this->assertSame('Cuti Online Disetujui', $setuju['judul']);
        $this->assertStringEndsWith('disetujui oleh Citra.', $setuju['isi']);
        $this->assertStringNotContainsString('Ket:', $setuju['isi']);
        $this->assertSame('Cuti Online Ditolak', $tolak['judul']);
        $this->assertStringEndsWith('ditolak oleh Citra. Ket: Kuota penuh', $tolak['isi']);
        $this->assertSame('Cuti Online Ditangguhkan', $tunda['judul']);
        $this->assertStringNotContainsString('Ket:', $tunda['isi']);
    }

    public function testTolakTanpaAlasanTidakMenambahKet(): void
    {
        $this->assertStringNotContainsString('Ket:', TemplatNotifikasi::keputusan($this->cuti(), 2, 'Citra', null)['isi']);
        $this->assertStringNotContainsString('Ket:', TemplatNotifikasi::keputusan($this->cuti(), 2, 'Citra', '   ')['isi']);
    }

    public function testTembusanStatus(): void
    {
        $this->assertStringEndsWith('sedang diproses.', TemplatNotifikasi::tembusanStatus($this->cuti(), 3, '')['isi']);
        $this->assertStringEndsWith('disetujui oleh Citra.', TemplatNotifikasi::tembusanStatus($this->cuti(), 1, 'Citra')['isi']);
        $this->assertStringEndsWith('ditolak oleh Citra.', TemplatNotifikasi::tembusanStatus($this->cuti(), 2, 'Citra')['isi']);
        $this->assertSame('Tembusan Cuti Online', TemplatNotifikasi::tembusanStatus($this->cuti(), 4, 'Ani')['judul']);
    }

    public function testSuratTerbit(): void
    {
        $t = TemplatNotifikasi::suratTerbit($this->cuti());

        $this->assertSame('Surat Cuti Online Terbit', $t['judul']);
        $this->assertStringEndsWith('sudah ditandatangani secara elektronik.', $t['isi']);
    }

    public function testPerluAdminMenyebutMentriBilaNamaKosong(): void
    {
        $this->assertStringContainsString('menunggu Menteri yang belum dapat memproses di sistem. Proses atas nama melalui menu Cuti.', TemplatNotifikasi::perluAdmin($this->cuti(), '')['isi']);
        $this->assertStringContainsString('menunggu Citra yang belum', TemplatNotifikasi::perluAdmin($this->cuti(), 'Citra')['isi']);
        $this->assertSame('Permohonan Cuti Perlu Diproses Admin', TemplatNotifikasi::perluAdmin($this->cuti(), '')['judul']);
    }
}
