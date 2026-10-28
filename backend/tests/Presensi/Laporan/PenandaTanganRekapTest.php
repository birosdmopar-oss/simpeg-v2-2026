<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Libraries\Presensi\Laporan\LingkupLaporan;
use App\Libraries\Presensi\Laporan\PejabatPenandaTangan;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.4 AC-D08.10 (MAKE-041): penanda tangan rekap atas data DB — satu test per kasus (definitif, Plt status 1
 * berlaku pada tanggal cetak dan deterministik F2, jabatan kosong, pejabat tidak ditemukan, menteri, menteri belum diatur)
 * + aturan level Inspektorat (2) dan satker kepegawaian.
 *
 * @internal
 */
final class PenandaTanganRekapTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    private int $unit;

    private int $satker;

    private int $idPeriode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Lama Uji', 'status' => 2]);
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Uji', 'status' => 1]);
        $this->idPeriode = (int) $this->db->insertID();
        $this->unit      = $this->buatUnit();
        $this->satker    = $this->buatSatker($this->unit);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testDefinitif(): void
    {
        $jab = $this->strukturJabatan($this->satker, 3, 'Kepala Biro Uji');
        $nip = $this->buatPegawai(['nama' => 'Budi Pejabat', 'glr_awal' => 'Dr.'], ['id_jabatan' => $jab, 'tmtsk' => '2024-01-01']);
        $this->buatPegawai(['nama' => 'Lama Pensiun', 'status' => 2], ['id_jabatan' => $jab, 'tmtsk' => '2025-01-01']);

        $this->assertSame(['kasus' => 'definitif', 'jabatan' => 'Kepala Biro Uji', 'nama' => 'Dr. Budi Pejabat', 'nip' => $nip], $this->untuk(LingkupLaporan::SATKER, $this->satker));
    }

    public function testPltStatus1BerlakuPadaTanggalCetakDeterministik(): void
    {
        $jab  = $this->strukturJabatan($this->satker, 3, 'Kepala Biro Uji');
        $plt1 = $this->buatPegawai(['nama' => 'Plt Satu']);
        $plt2 = $this->buatPegawai(['nama' => 'Plt Dua']);
        $plt3 = $this->buatPegawai(['nama' => 'Plt Nonaktif']);
        $this->plt($plt1, $jab, '2026-06-01', '2026-12-31', 1);
        $this->plt($plt2, $jab, '2026-06-01', '2026-12-31', 1);
        $this->plt($plt3, $jab, '2026-10-01', '2026-12-31', 2);

        $hasil = $this->untuk(LingkupLaporan::SATKER, $this->satker);
        $this->assertSame(['plt', 'Plt. Kepala Biro Uji', 'Plt: Plt Dua', $plt2], [$hasil['kasus'], $hasil['jabatan'], $hasil['nama'], $hasil['nip']], 'status 2 diabaikan; seri tgl_mulai → id terbesar');
        $this->assertSame('jabatan_kosong', $this->untuk(LingkupLaporan::SATKER, $this->satker, '2027-01-02')['kasus'], 'Plt berakhir');
    }

    public function testJabatanKosongDanPejabatTidakDitemukan(): void
    {
        $this->strukturJabatan($this->satker, 3, 'Kepala Biro Uji');
        $this->assertSame(['kasus' => 'jabatan_kosong', 'jabatan' => 'Kepala Biro Uji', 'nama' => 'Jabatan kosong (belum ada pejabat definitif/Plt)', 'nip' => ''], $this->untuk(LingkupLaporan::UNIT, $this->unit));

        $lain = $this->buatSatker($this->buatUnit());
        $this->assertSame('pejabat_tidak_ditemukan', $this->untuk(LingkupLaporan::SATKER, $lain)['kasus']);
    }

    public function testMenteriDanMenteriBelumDiatur(): void
    {
        $this->assertSame('menteri_belum_diatur', $this->untuk(LingkupLaporan::KEMENTERIAN, null)['kasus']);

        $this->aturWebConfig(['jabatan_menteri_full' => 'Menteri Uji Republik Indonesia', 'nama_menteri' => 'Nama Menteri Uji']);
        $this->assertSame(['kasus' => 'menteri', 'jabatan' => 'Menteri Uji Republik Indonesia', 'nama' => 'Nama Menteri Uji', 'nip' => ''], $this->untuk(LingkupLaporan::KEMENTERIAN, null));
    }

    public function testInspektoratLevel2DanSatkerKepegawaian(): void
    {
        $this->strukturJabatan($this->satker, 2, 'Inspektur Jenderal Uji');
        $this->strukturJabatan($this->satker, 3, 'Inspektur Uji');
        $this->aturWebConfig(['id_unit_inspektorat' => (string) $this->unit]);
        $this->assertSame('Inspektur Jenderal Uji', $this->untuk(LingkupLaporan::SATKER, $this->satker)['jabatan'], 'unit inspektorat → level 2');

        $unitKepeg   = $this->buatUnit();
        $satkerKepeg = $this->buatSatker($unitKepeg);
        $satkerLain  = $this->buatSatker($unitKepeg);
        $this->strukturJabatan($satkerKepeg, 3, 'Kepala Biro SDM Uji');
        $this->strukturJabatan($satkerLain, 3, 'Kepala Biro Lain Uji');
        $this->aturWebConfig(['id_unit_kepegawaian' => (string) $unitKepeg, 'id_satker_kepegawaian' => (string) $satkerKepeg]);

        $this->assertSame('Kepala Biro SDM Uji', $this->untuk(LingkupLaporan::SATKER, $satkerLain)['jabatan'], 'unit kepegawaian → satker kepegawaian');
        $this->assertSame('Kepala Biro SDM Uji', $this->untuk(LingkupLaporan::UNIT, $unitKepeg)['jabatan']);
    }

    /**
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    private function untuk(string $tipe, ?int $id, string $tanggal = '2026-10-28'): array
    {
        return (new PejabatPenandaTangan($this->db, service('webConfigService'), new Presensi()))->untuk(new LingkupLaporan($tipe, $id, 'Uji'), $tanggal);
    }

    private function strukturJabatan(int $satker, int $level, string $nama): int
    {
        $this->db->table('jabatan')->insert(['id_group_jabatan' => $this->buatJabatan($satker)['id_group_jabatan'], 'id_satker' => $satker, 'jabatan' => $nama, 'status' => 1]);
        $id = (int) $this->db->insertID();
        $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $this->idPeriode, 'id_jabatan' => $id, 'periode_struktur_jabatan' => 'Periode Uji',
            'jabatan'                     => $nama, 'level_struktur' => $level, 'urutan' => 1, 'status' => 1,
        ]);

        return $id;
    }

    private function plt(string $nip, int $jabatan, string $mulai, string $akhir, int $status): void
    {
        $this->db->table('pegawai_plt')->insert(['nip_plt' => $nip, 'id_jabatan' => $jabatan, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => $status]);
    }
}
