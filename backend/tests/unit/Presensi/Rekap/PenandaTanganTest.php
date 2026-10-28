<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Rekap;

use App\Libraries\Presensi\Rekap\PenandaTangan;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PRD 5B §7.3.4 AC-D08.10 (MAKE-041): satu test per kasus blok tanda tangan (definitif, Plt, jabatan kosong, pejabat tidak
 * ditemukan, menteri, menteri belum diatur) + pilihan Plt deterministik (F2: berlaku pada tanggal cetak, `tgl_mulai`
 * terbaru lalu id terbesar).
 *
 * @internal
 */
final class PenandaTanganTest extends CIUnitTestCase
{
    private const PEJABAT = ['nama' => 'Dr. Budi Santoso, M.Si.', 'nip' => '197001012000031001'];

    private const PLT = ['nama' => 'Siti Aminah, S.E.', 'nip' => '198001012005012002'];

    public function testDefinitifMenangAtasPlt(): void
    {
        $this->assertSame(
            ['kasus' => 'definitif', 'jabatan' => 'Kepala Biro Uji', 'nama' => self::PEJABAT['nama'], 'nip' => self::PEJABAT['nip']],
            PenandaTangan::struktural('Kepala Biro Uji', self::PEJABAT, self::PLT),
        );
    }

    public function testPlt(): void
    {
        $this->assertSame(
            ['kasus' => 'plt', 'jabatan' => 'Plt. Kepala Biro Uji', 'nama' => 'Plt: ' . self::PLT['nama'], 'nip' => self::PLT['nip']],
            PenandaTangan::struktural('Kepala Biro Uji', null, self::PLT),
        );
    }

    public function testJabatanKosong(): void
    {
        $this->assertSame(
            ['kasus' => 'jabatan_kosong', 'jabatan' => 'Kepala Biro Uji', 'nama' => 'Jabatan kosong (belum ada pejabat definitif/Plt)', 'nip' => ''],
            PenandaTangan::struktural('Kepala Biro Uji', null, null),
        );
    }

    public function testPejabatTidakDitemukan(): void
    {
        foreach ([null, '  '] as $jabatan) {
            $this->assertSame(
                ['kasus' => 'pejabat_tidak_ditemukan', 'jabatan' => 'Pejabat tidak ditemukan', 'nama' => '', 'nip' => ''],
                PenandaTangan::struktural($jabatan, self::PEJABAT, self::PLT),
            );
        }
    }

    public function testMenteriDanMenteriBelumDiatur(): void
    {
        $this->assertSame(
            ['kasus' => 'menteri', 'jabatan' => 'Menteri Uji Republik Indonesia', 'nama' => 'Nama Menteri Uji', 'nip' => ''],
            PenandaTangan::menteri(' Menteri Uji Republik Indonesia ', 'Nama Menteri Uji'),
        );

        foreach ([[null, 'Nama'], ['Menteri', ''], [null, null]] as [$jabatan, $nama]) {
            $this->assertSame(
                ['kasus' => 'menteri_belum_diatur', 'jabatan' => 'Data menteri belum diatur di Web Config', 'nama' => '', 'nip' => ''],
                PenandaTangan::menteri($jabatan, $nama),
            );
        }
    }

    public function testPilihPltDeterministik(): void
    {
        $kandidat = [
            ['id_pegawai_plt' => 5, 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-12-31'],
            ['id_pegawai_plt' => 9, 'tgl_mulai' => '2026-06-01', 'tgl_akhir' => '2026-12-31'],
            ['id_pegawai_plt' => 7, 'tgl_mulai' => '2026-06-01', 'tgl_akhir' => null],
            ['id_pegawai_plt' => 11, 'tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-12-31'],
            ['id_pegawai_plt' => 12, 'tgl_mulai' => '2025-01-01', 'tgl_akhir' => '2026-10-27'],
        ];

        $this->assertSame(9, PenandaTangan::pilihPlt($kandidat, '2026-10-28')['id_pegawai_plt'] ?? null, 'tgl_mulai terbaru yang berlaku, seri → id terbesar');
        $this->assertSame(9, PenandaTangan::pilihPlt(array_reverse($kandidat), '2026-10-28')['id_pegawai_plt'] ?? null, 'tidak bergantung urutan masukan');
        $this->assertSame(11, PenandaTangan::pilihPlt($kandidat, '2026-11-01')['id_pegawai_plt'] ?? null, 'tgl_mulai inklusif');
        $this->assertSame(12, PenandaTangan::pilihPlt([$kandidat[4]], '2026-10-27')['id_pegawai_plt'] ?? null, 'tgl_akhir inklusif');
        $this->assertNull(PenandaTangan::pilihPlt([$kandidat[4]], '2026-10-28'), 'sudah berakhir');
        $this->assertNull(PenandaTangan::pilihPlt([], '2026-10-28'));
    }
}
