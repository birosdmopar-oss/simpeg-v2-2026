<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Waktu;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-086: view PDF yang menampilkan stempel UTC sebagai tanggal/jam bisnis memakai WIB. Batas 17:00:00 UTC = 00:00:00 WIB
 * hari berikutnya (16:59:59 UTC masih hari yang sama).
 *
 * @internal
 */
final class ViewPembacaWibTest extends CIUnitTestCase
{
    public function testTandaTerimaMenampilkanTanggalWib(): void
    {
        $html = view('pdf/tanda_terima_usulan', [
            'judul'   => 'KARPEG',
            'baris'   => ['nip' => '199001012020011001', 'created_at' => '2026-12-31 17:00:00', 'updated_at' => '2026-12-31 16:59:59'],
            'nama'    => 'Budi Uji',
            'pegawai' => [],
            'noTiket' => '0000271',
            'kode'    => 'K',
            'berkas'  => [],
            'qr'      => '',
            'dicetak' => '01-01-2027',
        ]);

        $this->assertStringContainsString('01-01-2027 00:00:00', $html, '17:00:00 UTC = tahun baru 00:00:00 WIB');
        $this->assertStringContainsString('31-12-2026 23:59:59', $html, '16:59:59 UTC = 23:59:59 WIB');
    }

    public function testSuratTbDanIbMemakaiTanggalWibUntukDitetapkan(): void
    {
        $tb = array_fill_keys(['ds_tb', 'no_ds_tb', 'gol_ruang', 'institusi_pendidikan', 'jabatan', 'jenjang_pendidikan_singkat', 'jurusan_pendidikan', 'lembaga_yang_mendanai', 'nama', 'nip', 'no_sk', 'pangkat', 'perihal_ds_tb', 'satker', 'tembusan', 'up', 'tgl_ds_tb'], '')
            + ['lama_tahun' => '2', 'tgl_mulai' => '2027-01-10', 'tgl_akhir' => '2029-01-09'];
        $ib = array_fill_keys(['ds_ib', 'no_ds_ib', 'gol_ruang', 'institusi_pendidikan', 'jabatan', 'jenjang_pendidikan_singkat', 'jurusan_pendidikan', 'nama', 'nip', 'no_sk', 'pangkat', 'perihal_ds_ib', 'satker', 'tembusan', 'up', 'tgl_ds_ib'], '')
            + ['tgl_mulai' => '2027-01-10', 'tgl_akhir' => '2029-01-09'];

        $kabiro = ['nip' => '1', 'nama' => 'Kabiro', 'jabatan' => 'Kepala Biro'];

        foreach ([['pdf/surat_tb', 'tb', $tb], ['pdf/surat_ib', 'ib', $ib]] as [$view, $kunci, $dasar]) {
            $batas   = view($view, [$kunci => $dasar + ['created_at' => '2026-12-31 08:00:00', 'updated_at' => '2026-12-31 17:00:00'], 'kabiro' => $kabiro, 'tujuan' => 'Biro']);
            $sebelum = view($view, [$kunci => $dasar + ['created_at' => '2026-12-31 08:00:00', 'updated_at' => '2026-12-31 16:59:59'], 'kabiro' => $kabiro, 'tujuan' => 'Biro']);

            $this->assertStringContainsString('pada tanggal 1 Januari 2027', $batas, "{$view}: 17:00:00 UTC = 1 Januari 2027 WIB");
            $this->assertStringContainsString('pada tanggal 31 Desember 2026', $sebelum, "{$view}: 16:59:59 UTC masih 31 Desember WIB");
        }
    }
}
