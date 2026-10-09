<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\PemilihSnapshot;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * WS-1 M1 (MAKE-004) — pemilih snapshot sebagai fungsi murni, per aturan dok DBV-012 §5.1 (tanpa DB).
 *
 * @internal
 */
final class PemilihSnapshotTest extends CIUnitTestCase
{
    public function testHanyaBarisDisetujuiYangDipilih(): void
    {
        $aturan = new AturanSnapshot('pegawai_kgb', ['tmtsk'], urutan: ['tmtsk' => 'DESC']);
        $baris  = [
            $this->kgb(1, '2020-01-01', 1),
            $this->kgb(2, '2024-01-01', 0),
            $this->kgb(3, '2025-01-01', 2),
            $this->kgb(4, '2026-01-01', 10),
        ];

        $this->assertSame(1, PemilihSnapshot::pilih($aturan, $baris, 'id')['id'] ?? null);
    }

    public function testTidakAdaKandidatMengembalikanNull(): void
    {
        $aturan = new AturanSnapshot('pegawai_kgb', ['tmtsk'], urutan: ['tmtsk' => 'DESC']);

        $this->assertNull(PemilihSnapshot::pilih($aturan, [], 'id'));
        $this->assertNull(PemilihSnapshot::pilih($aturan, [$this->kgb(1, '2020-01-01', 0)], 'id'));
    }

    public function testMultiTargetKpCpnsPns(): void
    {
        // dok §5.1: kp = id_jenis_kp != 6 tmtsk DESC; cpns = jenis 1 tmtsk ASC; pns = jenis 2 tmtsk ASC.
        $baris = [
            ['id' => 1, 'status' => '1', 'id_jenis_kp' => '1', 'tmtsk' => '2010-03-01'],
            ['id' => 2, 'status' => '1', 'id_jenis_kp' => '2', 'tmtsk' => '2011-04-01'],
            ['id' => 3, 'status' => '1', 'id_jenis_kp' => '3', 'tmtsk' => '2015-04-01'],
            ['id' => 4, 'status' => '1', 'id_jenis_kp' => '6', 'tmtsk' => '2020-10-01'],
            ['id' => 5, 'status' => '1', 'id_jenis_kp' => '2', 'tmtsk' => '2012-04-01'],
        ];

        $kp   = new AturanSnapshot('pegawai_kp', ['tmtsk'], filter: ['id_jenis_kp !=' => 6], urutan: ['tmtsk' => 'DESC']);
        $cpns = new AturanSnapshot('pegawai_cpns', ['tmtsk'], filter: ['id_jenis_kp' => 1], urutan: ['tmtsk' => 'ASC']);
        $pns  = new AturanSnapshot('pegawai_pns', ['tmtsk'], filter: ['id_jenis_kp' => 2], urutan: ['tmtsk' => 'ASC']);

        $this->assertSame(3, PemilihSnapshot::pilih($kp, $baris, 'id')['id'] ?? null, 'jenis 6 dikecualikan');
        $this->assertSame(1, PemilihSnapshot::pilih($cpns, $baris, 'id')['id'] ?? null);
        $this->assertSame(2, PemilihSnapshot::pilih($pns, $baris, 'id')['id'] ?? null, 'PNS paling awal');
    }

    public function testFilterLogikaTigaNilaiSql(): void
    {
        // `id_jenis_kp != 6` di SQL tidak memilih baris ber-id_jenis_kp NULL.
        $kp    = new AturanSnapshot('pegawai_kp', ['tmtsk'], filter: ['id_jenis_kp !=' => 6], urutan: ['tmtsk' => 'DESC']);
        $baris = [
            ['id' => 1, 'status' => 1, 'id_jenis_kp' => null, 'tmtsk' => '2025-04-01'],
            ['id' => 2, 'status' => 1, 'id_jenis_kp' => 3, 'tmtsk' => '2020-04-01'],
        ];

        $this->assertSame(2, PemilihSnapshot::pilih($kp, $baris, 'id')['id'] ?? null);

        $this->assertTrue(PemilihSnapshot::lolosFilter(['a' => null], ['a' => null]));
        $this->assertFalse(PemilihSnapshot::lolosFilter(['a' => null], ['a' => 0]));
        $this->assertTrue(PemilihSnapshot::lolosFilter(['a !=' => null], ['a' => 0]));
        $this->assertFalse(PemilihSnapshot::lolosFilter(['a !=' => null], ['a' => null]));
        $this->assertFalse(PemilihSnapshot::lolosFilter(['a' => [1, 2]], ['a' => null]));
        $this->assertFalse(PemilihSnapshot::lolosFilter(['a !=' => [1, 2]], ['a' => null]));
        $this->assertTrue(PemilihSnapshot::lolosFilter(['a !=' => [1, 2]], ['a' => '3']));
    }

    public function testFilterInTandaJasa(): void
    {
        // dok §5.1: pegawai_tanda_jasa = id_tanda_jasa IN (26, 27, 28), tgl_sertifikat DESC.
        $aturan = new AturanSnapshot('pegawai_tanda_jasa', ['tgl_sertifikat'], filter: ['id_tanda_jasa' => [26, 27, 28]], urutan: ['tgl_sertifikat' => 'DESC']);
        $baris  = [
            ['id' => 1, 'status' => '1', 'id_tanda_jasa' => '26', 'tgl_sertifikat' => '2010-08-17'],
            ['id' => 2, 'status' => '1', 'id_tanda_jasa' => '5', 'tgl_sertifikat' => '2024-08-17'],
            ['id' => 3, 'status' => '1', 'id_tanda_jasa' => '28', 'tgl_sertifikat' => '2020-08-17'],
        ];

        $this->assertSame(3, PemilihSnapshot::pilih($aturan, $baris, 'id')['id'] ?? null);
    }

    public function testUrutanBerprioritasDenganKolomJoin(): void
    {
        // Jenjang tertinggi (order join) mengalahkan tanggal lulus terbaru.
        $aturan = new AturanSnapshot(
            'pegawai_pendidikan',
            ['tgl_lulus'],
            urutan: ['jenjang_pendidikan.order' => 'DESC', 'tgl_lulus' => 'DESC'],
            join: ['jenjang_pendidikan' => 'jenjang_pendidikan.id_jenjang_pendidikan = riwayat_pendidikan.id_jenjang_pendidikan'],
        );
        $baris = [
            ['id' => 1, 'status' => '1', 'jenjang_pendidikan.order' => '7', 'tgl_lulus' => '2012-08-30'],
            ['id' => 2, 'status' => '1', 'jenjang_pendidikan.order' => '6', 'tgl_lulus' => '2020-08-30'],
            ['id' => 3, 'status' => '1', 'jenjang_pendidikan.order' => '7', 'tgl_lulus' => '2015-08-30'],
            ['id' => 4, 'status' => '1', 'jenjang_pendidikan.order' => null, 'tgl_lulus' => '2025-08-30'],
        ];

        $this->assertSame(3, PemilihSnapshot::pilih($aturan, $baris, 'id')['id'] ?? null);
    }

    public function testNullTerkecilSepertiMysql(): void
    {
        $desc  = new AturanSnapshot('pegawai_x', ['t'], urutan: ['t' => 'DESC']);
        $asc   = new AturanSnapshot('pegawai_x', ['t'], urutan: ['t' => 'ASC']);
        $baris = [
            ['id' => 1, 'status' => 1, 't' => null],
            ['id' => 2, 'status' => 1, 't' => '2020-01-01'],
        ];

        $this->assertSame(2, PemilihSnapshot::pilih($desc, $baris, 'id')['id'] ?? null, 'DESC: NULL terakhir');
        $this->assertSame(1, PemilihSnapshot::pilih($asc, $baris, 'id')['id'] ?? null, 'ASC: NULL pertama');
    }

    public function testAngkaDibandingkanSebagaiAngka(): void
    {
        $aturan = new AturanSnapshot('pegawai_x', ['n'], urutan: ['n' => 'DESC']);
        $baris  = [
            ['id' => 1, 'status' => 1, 'n' => '9'],
            ['id' => 2, 'status' => 1, 'n' => '10'],
        ];

        $this->assertSame(2, PemilihSnapshot::pilih($aturan, $baris, 'id')['id'] ?? null);
    }

    public function testSeriDimenangkanPkTerbesar(): void
    {
        // Mutasi jabatan bertanggal sama: yang dicatat/disetujui belakangan menang (dok §5.1, B-07).
        $aturan = new AturanSnapshot('pegawai_mutasi_jabatan', ['tmtsk'], filter: ['jenis_jabatan !=' => 3], urutan: ['tmtsk' => 'DESC']);
        $baris  = [
            ['id' => 7, 'status' => 1, 'jenis_jabatan' => 1, 'tmtsk' => '2024-01-01'],
            ['id' => 9, 'status' => 1, 'jenis_jabatan' => 2, 'tmtsk' => '2024-01-01'],
            ['id' => 8, 'status' => 1, 'jenis_jabatan' => 1, 'tmtsk' => '2024-01-01'],
            ['id' => 10, 'status' => 1, 'jenis_jabatan' => 3, 'tmtsk' => '2024-01-01'],
        ];

        $this->assertSame(9, PemilihSnapshot::pilih($aturan, $baris, 'id')['id'] ?? null);
    }

    public function testPemetaanStatusDefinisiDipakai(): void
    {
        // Tabel legacy yang memakai nilai lain untuk Disetujui (mis. 5) tetap dipilih lewat pemetaanStatus().
        $aturan = new AturanSnapshot('pegawai_x', ['t'], urutan: ['t' => 'DESC']);
        $baris  = [
            ['id' => 1, 'st' => 1, 't' => '2025-01-01'],
            ['id' => 2, 'st' => 5, 't' => '2020-01-01'],
        ];
        $peta = [0 => StatusRiwayat::Menunggu, 5 => StatusRiwayat::Disetujui, 1 => StatusRiwayat::Ditolak];

        $this->assertSame(2, PemilihSnapshot::pilih($aturan, $baris, 'id', 'st', $peta)['id'] ?? null);
    }

    public function testKolomFilterTidakAdaGagalKeras(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PemilihSnapshot::pilih(new AturanSnapshot('pegawai_x', ['t'], filter: ['tidak_ada' => 1]), [['id' => 1, 'status' => 1, 't' => 'x']], 'id');
    }

    /**
     * @return array<string, int|string>
     */
    private function kgb(int $id, string $tmt, int $status): array
    {
        return ['id' => $id, 'status' => (string) $status, 'tmtsk' => $tmt];
    }
}
