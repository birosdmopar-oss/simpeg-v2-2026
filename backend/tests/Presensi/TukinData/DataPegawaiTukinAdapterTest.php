<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * PRD 5A §7.7.5 — AC-TK.5 (bagian data pegawai): predikat SKP periode BKN, jumlah anak s.d. akhir periode, TMT masuk
 * pegawai baru (CPNS/PPPK), TMT keluar. Data sintetis.
 *
 * @internal
 */
final class DataPegawaiTukinAdapterTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private PeriodeTukin $april;

    protected function setUp(): void
    {
        parent::setUp();
        $this->april = PeriodeTukin::dariBulan(2026, 4); // 2026-03-16 .. 2026-04-15

        // Master jenis pegawai sintetis (id legacy: 1 PNS, 6 PPPK = JP_PPPK `config/constants.php:301`).
        foreach ([1 => 'PNS Uji', 6 => 'PPPK Uji'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    public function testPredikatSkpPeriodeBkn(): void
    {
        $nip = $this->buatPegawai();
        $this->skp($nip, 'BKN-04', 'Baik', 1);
        $this->skp($nip, 'BKN-04', 'Sangat Kurang', 0); // menunggu → diabaikan
        $this->skp($nip, 'BKN-03', 'Kurang', 1);        // periode lain

        $this->assertSame('baik', $this->sumber()->predikatSkp($nip, 'BKN-04'), 'huruf kecil, status 1, periode BKN');
        $this->assertNull($this->sumber()->predikatSkp($nip, null), 'tanpa baris BKN → null');
        $this->assertNull($this->sumber()->predikatSkp($nip, 'BKN-05'), 'tanpa data → null');
    }

    public function testJumlahAnakSampaiAkhirPeriode(): void
    {
        $nip = $this->buatPegawai();
        $k1  = $this->keluarga($nip, '2015-06-01', 1);
        $k2  = $this->keluarga($nip, '2026-04-20', 1); // menikah setelah akhir periode → tidak dihitung [K :12182]
        $k3  = $this->keluarga($nip, '2010-01-01', 2); // ditolak → tidak dihitung

        $this->anak($k1, '2016-02-01');
        $this->anak($k1, '2026-04-15'); // tepat akhir periode → dihitung
        $this->anak($k1, '2026-04-16'); // lahir setelah akhir periode
        $this->anak($k2, '2020-01-01');
        $this->anak($k3, '2012-01-01');

        $this->assertSame(2, $this->sumber()->jumlahAnak($nip, $this->april->akhir));
    }

    public function testTmtKeluarHanyaStatusDua(): void
    {
        $aktif  = $this->buatPegawai();
        $keluar = $this->buatPegawai(['status' => 2, 'tmt_status' => '2026-04-01']);

        $this->assertNull($this->sumber()->untuk($aktif, $this->april, null)->tmtKeluar);
        $this->assertSame('2026-04-01', $this->sumber()->untuk($keluar, $this->april, null)->tmtKeluar);
    }

    public function testTmtMasukCpnsDariRiwayatKp(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($nip, '2026-03-01', 'CPNS III/a');
        $this->kp($nip, '2026-03-20', 'III/a'); // setelah awal periode → tidak dipakai [K riwayat_kp tmtsk ≤ awal periode]

        $this->assertSame('2026-03-01', $this->sumber()->untuk($nip, $this->april, null)->tmtMasuk);

        $pns = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($pns, '2020-03-01', 'III/b');
        $this->assertNull($this->sumber()->untuk($pns, $this->april, null)->tmtMasuk, 'bukan CPNS');
    }

    public function testTmtMasukPppkSetelahHariKerjaPertama(): void
    {
        $baru = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($baru, ['tmtsk' => '2026-04-01']);
        $this->assertSame('2026-04-01', $this->sumber()->untuk($baru, $this->april, null)->tmtMasuk);

        $lama = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($lama, ['tmtsk' => '2024-01-01']);
        $this->assertNull($this->sumber()->untuk($lama, $this->april, null)->tmtMasuk, 'TMT sebelum hari kerja pertama');

        // Hari kerja pertama periode April 2026 = Senin 2026-03-16; TMT hari itu bukan "setelah".
        $tepat = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($tepat, ['tmtsk' => '2026-03-16']);
        $this->assertNull($this->sumber()->untuk($tepat, $this->april, null)->tmtMasuk);
    }

    public function testPegawaiTidakAdaDitolakTerkendali(): void
    {
        $this->expectException(DataTukinTidakSah::class);
        $this->sumber()->untuk('199912312099121099', $this->april, null);
    }

    private function sumber(): SumberDataPegawaiTukin
    {
        return new SumberDataPegawaiTukin($this->db);
    }

    private function skp(string $nip, string $periode, string $hasil, int $status): void
    {
        $this->db->table('riwayat_skp_periodik')->insert([
            'id_pns' => 'PNS-' . $nip, 'nip' => $nip, 'nama' => 'Pegawai Uji', 'periode_id' => $periode, 'hasil_akhir' => $hasil, 'status' => $status,
        ]);
    }

    private function keluarga(string $nip, string $tglPerkawinan, int $status): int
    {
        $this->db->table('riwayat_keluarga')->insert([
            'nip' => $nip, 'tgl_perkawinan' => $tglPerkawinan, 'nama_pasangan' => 'Pasangan Uji', 'status' => $status,
        ]);

        return (int) $this->db->insertID();
    }

    private function anak(int $idKeluarga, string $tglLahir): void
    {
        $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $idKeluarga, 'tgl_lahir' => $tglLahir, 'nama' => 'Anak Uji']);
    }

    private function kp(string $nip, string $tmtsk, string $golRuang): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmtsk, 'gol_ruang' => $golRuang, 'status' => 1]);
    }
}
