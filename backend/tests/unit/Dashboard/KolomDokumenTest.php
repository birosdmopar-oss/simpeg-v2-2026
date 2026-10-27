<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Dokumen\KolomDokumen;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7-16/F7-23/F7-24 kolom dokumen (MAKE-037): jumlah & urutan kolom XLSX (37/12), subset PDF, format tanggal
 * `d M Y`, nama bergelar, eselon E.I–E.IV, usia/MKER/KGB per hari ini, TMT UNIT = TMT jabatan, pensiun PNS berjabatan.
 *
 * @internal
 */
final class KolomDokumenTest extends CIUnitTestCase
{
    public function testJumlahDanUrutanKolom(): void
    {
        $this->assertCount(37, KolomDokumen::KOLOM_LENGKAP);
        $this->assertSame('NAMA', KolomDokumen::KOLOM_LENGKAP['nama']);
        $this->assertSame(['nama', 'nip', 'nip_lama', 'gol_ruang', 'tmtsk_pangkat'], array_slice(array_keys(KolomDokumen::KOLOM_LENGKAP), 0, 5));
        $this->assertSame(['eselon', 'tgl_pensiun', 'pensiun_th', 'pensiun_bl'], array_slice(array_keys(KolomDokumen::KOLOM_LENGKAP), -4));
        $this->assertCount(12, KolomDokumen::KOLOM_PENSIUN);
        $this->assertSame(array_keys(KolomDokumen::KOLOM_LENGKAP), KolomDokumen::kolomXlsx('duk'));
        $this->assertSame(array_keys(KolomDokumen::KOLOM_LENGKAP), KolomDokumen::kolomXlsx('baperjakat'));
        $this->assertSame(array_keys(KolomDokumen::KOLOM_PENSIUN), KolomDokumen::kolomXlsx('pensiun'));
        $this->assertSame(array_values(KolomDokumen::KOLOM_LENGKAP), KolomDokumen::headerXlsx('nominatif'));
        $this->assertCount(13, KolomDokumen::KOLOM_PDF);
        $this->assertSame(KolomDokumen::KOLOM_PDF, KolomDokumen::kolomPdf('duk'));
        $this->assertSame(KolomDokumen::KOLOM_PENSIUN_PDF, KolomDokumen::kolomPdf('pensiun'));
        $this->assertSame(array_keys(KolomDokumen::KOLOM_PENSIUN), array_keys(KolomDokumen::KOLOM_PENSIUN_PDF), 'kunci sama dengan XLSX/JSON; hanya label PDF yang berbeda');
    }

    public function testBarisLengkap(): void
    {
        $b = KolomDokumen::baris([
            'nip'                        => '199001012015011001', 'nip_lama' => '010203', 'nama' => 'Budi Santoso', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.',
            'tgl_lahir'                  => '1990-03-15', 'jenis_kelamin' => '1', 'agama' => 'Islam',
            'kabupaten_kota_lahir'       => 'LAIN-LAIN', 'kabupaten_kota_lahir_lain' => 'Kota Fiktif', 'id_jenis_pegawai' => '1',
            'gol'                        => 'III', 'ruang' => 'c', 'gol_ruang' => 'III/c', 'pangkat' => 'Penata', 'tmtsk_pangkat' => '2022-04-01', 'pangkat_order' => 11,
            'id_unit'                    => 3, 'unit' => 'Unit A', 'id_satker' => 31, 'satker' => 'Satker A1', 'id_jabatan' => 7, 'jabatan' => 'Analis', 'tmtsk_jabatan' => '2023-01-10',
            'id_group_jabatan'           => 1, 'id_sub_group_jabatan' => 3, 'kelas_jabatan' => 9, 'umur_pensiun' => null,
            'tmtsk_cpns'                 => '2015-01-01', 'tmtsk_pns' => '2016-02-01', 'nama_diklat' => 'PKP',
            'jenjang_pendidikan_singkat' => 'S2', 'jurusan_pendidikan' => 'LAIN-LAIN', 'jurusan_pendidikan_lain' => 'Kebijakan Publik', 'institusi_pendidikan' => 'UI', 'tgl_lulus' => '2014-08-20', 'ipk' => '3,75',
            'tmtsk_kgb'                  => '2024-01-01', 'tgl_sertifikat' => '2025-08-17', 'tanda_jasa' => 'Satyalancana X',
        ], '2026-10-21');

        $this->assertSame('Dr. Budi Santoso, M.Si.', $b['nama']);
        $this->assertSame('15 Mar 1990', $b['tgl_lahir']);
        $this->assertSame('01 Apr 2022', $b['tmtsk_pangkat']);
        $this->assertSame('10 Jan 2023', $b['tmtsk_satker'], 'TMT UNIT = TMT jabatan snapshot (F7-23)');
        $this->assertSame('Kota Fiktif', $b['tempat_lahir']);
        $this->assertSame('Kebijakan Publik', $b['jurusan']);
        $this->assertSame('3.75', $b['ipk']);
        $this->assertSame('LAKI-LAKI', $b['jenis_kelamin']);
        $this->assertSame(['36', '7'], [$b['usia_th'], $b['usia_bl']]);
        $this->assertSame(['11', '9'], [$b['mker_th'], $b['mker_bl']]);
        $this->assertSame(['2', '9', '02 Tahun 09 Bulan'], [$b['kgb_th'], $b['kgb_bl'], $b['kgb_mker']], 'format MasaKerja::format legacy');
        $this->assertSame('E.III', $b['eselon']);
        // BUP 58 (bukan E.I/II, umur_pensiun kosong) → TMT 1 April 2048.
        $this->assertSame(['01 Apr 2048', '58', '4'], [$b['tgl_pensiun'], $b['pensiun_th'], $b['pensiun_bl']]);
        $this->assertSame('Penata (III/c)', $b['pangkat_gol']);
        $this->assertSame('Unit A / Satker A1', $b['unit_satker']);
        $this->assertSame('S2 Kebijakan Publik', $b['pendidikan']);
        $this->assertSame('11 Tahun 09 Bulan', $b['masa_kerja']);
        $this->assertSame('15 Mar 1990 (36 th)', $b['lahir_usia']);
        $this->assertSame('Satyalancana X', $b['tanda_jasa']);
        $this->assertSame('PKP', $b['diklat']);
    }

    public function testBarisKosongDanPerempuanTanpaJabatan(): void
    {
        $b = KolomDokumen::baris(['nip' => '1', 'nama' => 'Ani', 'tgl_lahir' => '1988-12-31', 'jenis_kelamin' => '2', 'id_jenis_pegawai' => '6'], '2026-10-21');

        $this->assertSame('Ani', $b['nama']);
        $this->assertSame('PEREMPUAN', $b['jenis_kelamin']);
        $this->assertSame('', $b['tmtsk_pangkat']);
        $this->assertSame('', $b['eselon']);
        $this->assertSame('', $b['tgl_pensiun'], 'PPPK tanpa jabatan → tanpa pensiun');
        $this->assertSame('', $b['mker_th']);
        $this->assertSame('', $b['kgb_mker']);
        $this->assertSame('', $b['pangkat_gol']);
        $this->assertSame('37', $b['usia_th']);
    }

    public function testPensiunEselonDuaDanUmurPensiunJabatan(): void
    {
        $dasar = ['nip' => '1', 'nama' => 'X', 'tgl_lahir' => '1968-01-31', 'id_jenis_pegawai' => '1', 'id_jabatan' => 5];

        $this->assertSame(['bup' => 60, 'tmt' => '2028-02-01'], KolomDokumen::pensiun($dasar + ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 2]));
        $this->assertSame(['bup' => 65, 'tmt' => '2033-02-01'], KolomDokumen::pensiun($dasar + ['umur_pensiun' => 65, 'id_group_jabatan' => 2]));
        $this->assertSame(['bup' => 58, 'tmt' => '2026-02-01'], KolomDokumen::pensiun($dasar + ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 3]));
        $this->assertNull(KolomDokumen::pensiun(['nip' => '1', 'tgl_lahir' => '1968-01-31', 'id_jenis_pegawai' => '1']), 'tanpa jabatan aktif');
        $this->assertNull(KolomDokumen::pensiun(array_merge($dasar, ['id_jenis_pegawai' => '6'])), 'PPPK tidak masuk');
        $this->assertNull(KolomDokumen::pensiun(['nip' => '1', 'tgl_lahir' => '0000-00-00', 'id_jenis_pegawai' => '1', 'id_jabatan' => 5]), 'tanggal lahir rusak');
    }

    public function testEselonDanTanggal(): void
    {
        $this->assertSame('E.I', KolomDokumen::eselon(1, 1));
        $this->assertSame('E.IV', KolomDokumen::eselon('1', '4'));
        $this->assertSame('', KolomDokumen::eselon(1, 5));
        $this->assertSame('', KolomDokumen::eselon(2, 1));
        $this->assertSame('', KolomDokumen::eselon(null, null));
        $this->assertSame('05 Mar 2024', KolomDokumen::tanggal('2024-03-05'));
        $this->assertSame('', KolomDokumen::tanggal('0000-00-00'));
        $this->assertSame('', KolomDokumen::tanggal(null));
    }
}
