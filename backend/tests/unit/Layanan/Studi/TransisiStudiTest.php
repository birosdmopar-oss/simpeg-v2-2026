<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Studi;

use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\TransisiStudi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-024 — state machine satu langkah TB/IB (PRD Fase 4 v1.1 §6.7.3, §6.8.2, D14): 0 → {1, 2}; data SK wajib saat
 * setujui, alasan wajib & ≤ 255 byte saat tolak; 1/2 final dan 3 (impor) tidak dapat diproses; single end node.
 *
 * @internal
 */
final class TransisiStudiTest extends CIUnitTestCase
{
    private const SK = [
        AturanStudi::JENIS_TB => ['no_sk' => '1/SK', 'tgl_sk' => '2025-08-01', 'ds_tb' => 'Surat', 'no_ds_tb' => '2/DS'],
        AturanStudi::JENIS_IB => ['no_sk' => '1/SK', 'tgl_sk' => '2025-08-01', 'ds_ib' => 'Surat', 'no_ds_ib' => '2/DS'],
    ];

    public function testSetujuiDanTolakDari0(): void
    {
        $a = AturanStudi::bawaan();

        foreach (AturanStudi::JENIS as $jenis) {
            $setuju = TransisiStudi::proses($jenis, 0, TransisiStudi::AKSI_SETUJUI, self::SK[$jenis], 'diabaikan', $a);
            $this->assertTrue($setuju->valid, $jenis);
            $this->assertSame(['status' => 1, 'final' => true, 'efek_final' => true, 'show_pl' => 1, 'reason_note' => null], $setuju->detail);

            $tolak = TransisiStudi::proses($jenis, 0, TransisiStudi::AKSI_TOLAK, [], '  Berkas kurang  ', $a);
            $this->assertTrue($tolak->valid, $jenis);
            $this->assertSame(['status' => 2, 'final' => true, 'efek_final' => false, 'show_pl' => null, 'reason_note' => 'Berkas kurang'], $tolak->detail);
        }
    }

    public function testSetujuiTanpaDataSkMengembalikanSemuaGalat(): void
    {
        $tb = TransisiStudi::proses(AturanStudi::JENIS_TB, 0, TransisiStudi::AKSI_SETUJUI, ['no_sk' => '1/SK'], null, AturanStudi::bawaan());
        $this->assertFalse($tb->valid);
        $this->assertSame('tgl_sk', $tb->kode);
        $this->assertSame(['tgl_sk', 'ds_tb', 'no_ds_tb'], array_keys($tb->detail['errors']));

        // Field SK IB berbeda: data SK TB tidak memenuhi IB.
        $ib = TransisiStudi::proses(AturanStudi::JENIS_IB, 0, TransisiStudi::AKSI_SETUJUI, self::SK[AturanStudi::JENIS_TB], null, AturanStudi::bawaan());
        $this->assertSame(['ds_ib', 'no_ds_ib'], array_keys($ib->detail['errors']));
        $this->assertSame('Dasar Surat Izin Belajar wajib diisi.', $ib->pesan);
    }

    public function testGalatAksiAlasanDanStatus(): void
    {
        $a = AturanStudi::bawaan();

        $aksi = TransisiStudi::proses(AturanStudi::JENIS_TB, 0, 'tangguhkan', [], null, $a);
        $this->assertSame(['aksi', 'Silahkan setujui atau tolak permohonan data tugas belajar pegawai.'], [$aksi->kode, $aksi->pesan]);

        $kosong = TransisiStudi::proses(AturanStudi::JENIS_IB, 0, TransisiStudi::AKSI_TOLAK, [], '   ', $a);
        $this->assertSame(['reason_note', TransisiStudi::PESAN_REASON_WAJIB], [$kosong->kode, $kosong->pesan]);

        // 255 byte boleh, 256 byte (multibyte) tidak.
        $this->assertTrue(TransisiStudi::proses(AturanStudi::JENIS_TB, 0, TransisiStudi::AKSI_TOLAK, [], str_repeat('a', 255), $a)->valid);
        $panjang = TransisiStudi::proses(AturanStudi::JENIS_TB, 0, TransisiStudi::AKSI_TOLAK, [], str_repeat('é', 128), $a);
        $this->assertSame('reason_note', $panjang->kode);

        foreach ([StatusStudi::DISETUJUI, StatusStudi::DITOLAK, StatusStudi::DIPROSES, 4] as $status) {
            $hasil = TransisiStudi::proses(AturanStudi::JENIS_TB, $status, TransisiStudi::AKSI_SETUJUI, self::SK[AturanStudi::JENIS_TB], null, $a);
            $this->assertSame('status', $hasil->kode, "status {$status}");
        }
    }

    /**
     * Single end node: dari 0, setiap aksi sah berakhir final dalam 1 keputusan; status final tidak punya aksi sah.
     */
    public function testSingleEndNode(): void
    {
        $a = AturanStudi::bawaan();

        foreach (AturanStudi::JENIS as $jenis) {
            $tujuan = [];

            foreach (TransisiStudi::AKSI as $aksi) {
                $hasil = TransisiStudi::proses($jenis, 0, $aksi, self::SK[$jenis], 'alasan', $a);
                $this->assertTrue($hasil->valid);
                $this->assertTrue(StatusStudi::final($hasil->detail['status']));
                $tujuan[] = $hasil->detail['status'];

                foreach (TransisiStudi::AKSI as $lanjut) {
                    $this->assertFalse(TransisiStudi::proses($jenis, $hasil->detail['status'], $lanjut, self::SK[$jenis], 'alasan', $a)->valid);
                }
            }

            $this->assertSame([StatusStudi::DISETUJUI, StatusStudi::DITOLAK], $tujuan);
        }

        $this->assertSame('Pengajuan', StatusStudi::label(0));
        $this->assertSame('Diproses', StatusStudi::label(3));
        $this->assertSame('Tidak dikenal', StatusStudi::label(10));
    }
}
