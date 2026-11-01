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

        // Master jenis pegawai sintetis. id 6 = PPPK (JP_PPPK `config/constants.php:301`) memakai nama panjang seperti seed v2;
        // id 2 sengaja bernama 'PPPK' untuk membuktikan cabang PPPK dikenali lewat id, bukan nama [V2].
        foreach ([1 => 'Pegawai Negeri Sipil', 2 => 'PPPK', 6 => 'Pegawai Pemerintah dengan Perjanjian Kerja'] as $id => $nama) {
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

        $this->assertSame('2026-03-01', $this->tmt($nip), 'CPNS: tmtsk tanpa syarat hari kerja pertama');

        $pns = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($pns, '2020-03-01', 'III/b');
        $this->assertNull($this->tmt($pns), 'bukan CPNS');
    }

    public function testTanpaRiwayatKpNull(): void
    {
        $this->assertNull($this->tmt($this->buatPegawai(['id_jenis_pegawai' => 1])));
    }

    public function testPegawaiDibuatSetelahAkhirPeriodePakaiBatasAkhir(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-20 09:00:00']);
        $this->kp($nip, '2026-04-01', 'CPNS III/a'); // > awal periode, ≤ akhir periode [K L_employee.php:6453]

        $this->assertSame('2026-04-01', $this->tmt($nip));

        $lama = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-01-05 09:00:00']);
        $this->kp($lama, '2026-04-01', 'CPNS III/a');
        $this->assertNull($this->tmt($lama), 'dibuat sebelum akhir periode: batas tetap awal periode');
    }

    public function testTmtMasukPppkLewatIdApapunNamanya(): void
    {
        // Hari kerja pertama periode April 2026 = Senin 2026-03-16.
        $baru = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->kp($baru, '2020-01-01', 'III/a'); // ditimpa jabatan PPPK [K L_employee.php:6580-6584]
        $this->buatRiwayatMutasiJabatan($baru, ['tmtsk' => '2026-04-01', 'gol_pppk' => 'IX']);
        $this->assertSame('2026-04-01', $this->tmt($baru), 'id 6 bernama panjang masuk cabang PPPK');

        $sebelum = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($sebelum, ['tmtsk' => '2026-03-10', 'gol_pppk' => 'IX']);
        $this->assertNull($this->tmt($sebelum), 'TMT sebelum hari kerja pertama');

        $tepat = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($tepat, ['tmtsk' => '2026-03-16', 'gol_pppk' => 'IX']);
        $this->assertNull($this->tmt($tepat), 'TMT tepat hari kerja pertama bukan "setelah"');

        $libur = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($libur, ['tmtsk' => '2026-03-17', 'gol_pppk' => 'IX']);
        $this->assertNull(
            $this->sumber()->untuk($libur, $this->april, null, ['2026-03-16', '2026-03-17'])->tmtMasuk,
            'hari kerja pertama bergeser oleh libur (HariKerja) → 17 Maret bukan setelahnya',
        );
    }

    public function testIdSelainPppkTidakMasukCabangWalauBernamaPppk(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 2]); // nama master 'PPPK'
        $this->kp($nip, '2020-01-01', 'II/a');
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2026-04-01', 'gol_pppk' => 'IX']);

        $this->assertNull($this->tmt($nip));
    }

    /**
     * CR-083: PPPK tanpa TMT (tanpa `riwayat_mutasi_jabatan` jenis 1 & `riwayat_kp`) → `lewatiLoopHarian` di jalur tunggal
     * dan massal [K :12707-12710]; varian IL (`tanpaPegawaiBaru`) tanpa aturan pegawai baru [K IL :13494-15452].
     */
    public function testPppkTanpaTmtLewatiLoopJalurTunggalDanMassalKecualiIl(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($nip, ['jenis_jabatan' => 2, 'tmtsk' => '2025-01-02']); // jenis 2 tidak memberi TMT
        $ber = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($ber, ['tmtsk' => '2026-04-01', 'gol_pppk' => 'IX']);

        $tunggal = $this->sumber()->untuk($nip, $this->april, null);
        $this->assertSame([null, true], [$tunggal->tmtMasuk, $tunggal->lewatiLoopHarian], 'tunggal');
        $massal = $this->sumber()->untukBanyak([$nip, $ber], $this->april, null);
        $this->assertEquals($tunggal, $massal[$nip], 'massal = tunggal');
        $this->assertSame(['2026-04-01', false], [$massal[$ber]->tmtMasuk, $massal[$ber]->lewatiLoopHarian], 'PPPK ber-TMT tetap');

        foreach ([$this->sumber()->untuk($ber, $this->april, null, [], true), $this->sumber()->untukBanyak([$nip, $ber], $this->april, null, [], true)[$nip]] as $il) {
            $this->assertSame([null, false], [$il->tmtMasuk, $il->lewatiLoopHarian], 'IL');
        }
    }

    /**
     * CR-084 (B CR-083): "dibuat setelah akhir periode" (legacy `$dtCreatedAt > "akhir 23:59:59"` WIB,
     * `L_employee.php:6429-6433`) atas `created_at` UTC — akhir 15-04: `2026-04-15 17:00:00` UTC (16-04 00:00 WIB) =
     * setelah → pangkat ≤ akhir periode dipakai; `2026-04-15 16:59:59` UTC (15-04 23:59:59 WIB) = belum → hanya ≤ awal.
     * Jalur tunggal (`pangkat`) dan massal (`pangkatDari`).
     */
    public function testDibuatSetelahAkhirPeriodeBatasZonaWaktu(): void
    {
        $setelah = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-15 17:00:00']);
        $belum   = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-15 16:59:59']);

        foreach ([$setelah, $belum] as $n) {
            $this->kp($n, '2026-04-01', 'CPNS III/a'); // > awal periode, ≤ akhir periode
        }

        $massal = $this->sumber()->untukBanyak([$setelah, $belum], $this->april, null);
        $this->assertSame('2026-04-01', $this->tmt($setelah), 'tunggal: 16-04 00:00 WIB setelah akhir');
        $this->assertSame('2026-04-01', $massal[$setelah]->tmtMasuk, 'massal');
        $this->assertNull($this->tmt($belum), 'tunggal: 15-04 23:59:59 WIB belum setelah akhir');
        $this->assertNull($massal[$belum]->tmtMasuk, 'massal');
    }

    public function testPegawaiTidakAdaDitolakTerkendali(): void
    {
        $this->expectException(DataTukinTidakSah::class);
        $this->sumber()->untuk('199912312099121099', $this->april, null);
    }

    private function tmt(string $nip): ?string
    {
        return $this->sumber()->untuk($nip, $this->april, null)->tmtMasuk;
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
