<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\TarifUangMakan;
use App\Libraries\Presensi\Slip\UangMakanSlip;
use App\Libraries\Presensi\Slip\UangMakanSlipPresensi;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.2 AC-D09a.2/.3 (MAKE-040) — sumber uang makan slip asli {@see UangMakanSlipPresensi} atas data DB: nominal
 * = hari dibayar × tarif; pegawai tidak ada → `null`; tarif 0 → `(0, 0, 0)` tanpa menghitung hari; `gol_pppk` tidak
 * bulat sampai sen → `null`; nilai `web_config` tersimpan tak sah = bawaan katalog (0); hari dipotong hari ini WIB (jam disuntik); pemuat banyak NIP setara jalur satu NIP dengan jumlah kueri tetap.
 * Kalender: September 2026 (1 Sep = Selasa).
 *
 * @internal
 */
final class UangMakanSlipPresensiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(['uang_makan_gol_1' => '35000', 'uang_makan_gol_2' => '35000', 'uang_makan_gol_3' => '37000', 'uang_makan_gol_4' => '41000']);

        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', 1)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'Pegawai Negeri Sipil', 'status' => 1]);
        }
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testNominalHariKaliTarifDenganSemuaAturan(): void
    {
        $nip = $this->pns('III');
        $this->skenarioSeptember($nip);

        $hasil = $this->sumber('2026-10-27 09:00:00')->hitung($nip, PeriodeSlip::buat(2026, 9));

        // Dibayar: 1, 2, 3 (hadir), 4 (masuk saja), 11 (konket kategori 13) = 5 hari × Rp37.000.
        $this->assertEquals(new UangMakanSlip(18500000, 5, 3700000), $hasil);
        $this->assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-11'],
            $this->sumber('2026-10-27 09:00:00')->hariDibayarBanyak([$nip], 2026, 9)[$nip],
        );
    }

    public function testDipotongHariIniWib(): void
    {
        $nip = $this->pns('III');
        $this->skenarioSeptember($nip);

        // 3 Sep 06:59 WIB (2 Sep 23:59 UTC) → tanggal 3 masih ikut; 4 Sep belum.
        $hasil = $this->sumber(null, new DateTimeImmutable('2026-09-02 23:59:00', new DateTimeZone('UTC')))->hitung($nip, PeriodeSlip::buat(2026, 9));
        $this->assertEquals(new UangMakanSlip(11100000, 3, 3700000), $hasil);

        $this->assertEquals(new UangMakanSlip(0, 0, 3700000), $this->sumber('2026-08-31 23:00:00')->hitung($nip, PeriodeSlip::buat(2026, 9)), 'bulan belum mulai');
    }

    public function testTbBulanPenuhMenutupSisaBulanDanTbMulaiTengahBulanTidak(): void
    {
        $a = $this->pns('II');
        $b = $this->pns('II');

        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-28', '2026-09-29'] as $t) {
            $this->hadir($a, $t);
            $this->hadir($b, $t);
        }

        // A: TB mulai sebelum bulan ini (potongan 25) sampai 2 Sep → 1–2 dilewati sebagai TB, penanda melekat s.d. akhir bulan.
        $this->tb($a, '2025-09-01', '2026-09-02');
        // B: TB mulai 3 Sep (potongan 0) + perpanjangan 28 Sep → hanya tanggal TB yang dilewati.
        $idB = $this->tb($b, '2026-09-03', '2026-09-03');
        $this->db->table('riwayat_tb_perpan')->insert(['id_riwayat_tb' => $idB, 'tgl_mulai_perpan' => '2026-09-28', 'tgl_akhir_perpan' => '2026-09-28', 'file_sperpan' => 'uji.pdf']);

        $hari = $this->sumber('2026-10-27 09:00:00')->hariDibayarBanyak([$a, $b], 2026, 9);

        $this->assertSame([], $hari[$a]);
        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-29'], $hari[$b]);
    }

    public function testPegawaiTidakAdaTarifNolDanTarifTidakSah(): void
    {
        $this->assertNull($this->sumber('2026-10-27 09:00:00')->hitung('199912312099121099', PeriodeSlip::buat(2026, 9)));

        $tanpaKp = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->hadir($tanpaKp, '2026-09-01');
        $this->assertEquals(new UangMakanSlip(0, 0, 0), $this->sumber('2026-10-27 09:00:00')->hitung($tanpaKp, PeriodeSlip::buat(2026, 9)));

        // Nilai web_config tersimpan yang tidak sah dibaca service sebagai bawaan katalog (kosong) → tarif 0.
        $nip = $this->pns('IV');
        $this->hadir($nip, '2026-09-01');
        $this->db->table('web_config')->where('config_name', 'uang_makan_gol_4')->update(['config_value' => '41000.5']);
        $this->resetWebConfig();
        $this->assertEquals(new UangMakanSlip(0, 0, 0), $this->sumber('2026-10-27 09:00:00')->hitung($nip, PeriodeSlip::buat(2026, 9)));

        // gol_pppk.uang_makan tidak bulat sampai sen → DataUangMakanTidakSah → null (snapshot tidak ditulis, slip tetap terbuka).
        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', 6)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 6, 'jenis_pegawai' => 'PPPK', 'status' => 1]);
        }

        $this->db->table('gol_pppk')->insert(['id_gol_pppk' => 122, 'gol_pppk' => 'XI', 'uang_makan' => 40000.125, 'status' => 1]);
        $pppk = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($pppk, ['tmtsk' => '2025-01-01', 'id_gol_pppk' => 122, 'gol_pppk' => 'XI']);
        $this->hadir($pppk, '2026-09-01');
        $this->assertNull($this->sumber('2026-10-27 09:00:00')->hitung($pppk, PeriodeSlip::buat(2026, 9)));
    }

    public function testBanyakNipSetaraDanJumlahKueriTetap(): void
    {
        $nip = [];

        for ($i = 0; $i < 6; $i++) {
            $nip[] = $n = $this->pns('I');
            $this->skenarioSeptember($n, $i);
        }

        $sumber = $this->sumber('2026-10-27 09:00:00');
        $sumber->hariDibayarBanyak([$nip[0]], 2026, 9); // pemanasan cache

        $kueri  = $this->hitungSelect(fn () => $sumber->hariDibayarBanyak($nip, 2026, 9));
        $banyak = $sumber->hariDibayarBanyak($nip, 2026, 9);

        foreach ($nip as $n) {
            $this->assertSame($sumber->hariDibayarBanyak([$n], 2026, 9)[$n], $banyak[$n], "setara jalur satu NIP {$n}");
        }

        $sedikit = $this->hitungSelect(fn () => $sumber->hariDibayarBanyak([$nip[0], $nip[1]], 2026, 9));
        $this->assertSame($sedikit, $kueri, 'jumlah kueri tidak tumbuh dengan jumlah NIP');
        $this->assertLessThanOrEqual(6, $kueri);
        $this->assertSame([], $sumber->hariDibayarBanyak([], 2026, 9));
    }

    /**
     * Data September: hadir 1–3, masuk saja 4, cuti 7–8 (hadir 7 diabaikan), libur 9 (hadir diabaikan), konket
     * kategori 5 tanggal 10, konket kategori 13 tanggal 11, hadir Sabtu 12 (diabaikan), konket affect_tukin 2 tanggal 14
     * tanpa presensi. `$geser` hanya membedakan baris antar-pegawai.
     */
    private function skenarioSeptember(string $nip, int $geser = 0): void
    {
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-07', '2026-09-09', '2026-09-12'] as $t) {
            $this->hadir($nip, $t);
        }

        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => '2026-09-04', 'jam_masuk' => '07:30:00', 'jam_pulang' => null, 'sumber' => 1]);
        $this->cuti($nip, '2026-09-07', '2026-09-08');
        $this->konket($nip, '2026-09-10 00:00:00', '2026-09-10 23:59:59', 5, 1);
        $this->konket($nip, '2026-09-11 00:00:00', '2026-09-11 23:59:59', 13, 1);
        $this->konket($nip, '2026-09-14 00:00:00', '2026-09-14 23:59:59', 5 + $geser, 2);

        if ($this->db->table('hari_libur')->where('tgl_mulai', '2026-09-09')->countAllResults() === 0) {
            $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-09-09', 'tgl_akhir' => '2026-09-09', 'nama_libur' => 'Libur uji', 'status' => 1]);
        }
    }

    private function sumber(?string $wib, ?DateTimeInterface $instan = null): UangMakanSlipPresensi
    {
        $instan ??= new DateTimeImmutable((string) $wib, new DateTimeZone('Asia/Jakarta'));

        return new UangMakanSlipPresensi($this->db, new TarifUangMakan($this->db, service('webConfigService')), service('hariLiburService'), static fn (): DateTimeInterface => $instan);
    }

    private function pns(string $gol): string
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => '2020-01-01', 'gol' => $gol, 'gol_ruang' => $gol, 'status' => 1]);

        return $nip;
    }

    private function hadir(string $nip, string $tgl): void
    {
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => $tgl, 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:30:00', 'sumber' => 1]);
    }

    private function cuti(string $nip, string $mulai, string $akhir): void
    {
        if ($this->db->table('jenis_cuti')->where('id_jenis_cuti', 1)->countAllResults() === 0) {
            $this->db->table('jenis_cuti')->insert(['id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'status' => 1]);
        }

        $this->db->table('riwayat_cuti')->insert([
            'nip' => $nip, 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'lama_cuti' => 2, 'status' => 1,
        ]);
    }

    private function tb(string $nip, string $mulai, string $akhir): int
    {
        $this->db->table('riwayat_tb')->insert([
            'nip'                        => $nip, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => 1,
            'jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Jurusan Uji',
            'dalam_negeri'               => 1, 'lokasi_pendidikan' => 'Kota Uji', 'lembaga_yang_mendanai' => 'Lembaga Uji',
        ]);

        return (int) $this->db->insertID();
    }

    private function konket(string $nip, string $mulai, string $akhir, int $kategori, int $affectTukin): void
    {
        $this->db->table('absen_ijin')->insert([
            'nip' => $nip, 'date_start' => $mulai, 'date_end' => $akhir, 'kategori' => $kategori, 'alasan' => 'Uji', 'status' => 'V', 'affect_tukin' => $affectTukin,
        ]);
    }

    /**
     * @param callable(): mixed $aksi
     */
    private function hitungSelect(callable $aksi): int
    {
        $jumlah   = 0;
        $pencatat = static function (Query $q) use (&$jumlah): void {
            if (stripos(ltrim($q->getQuery()), 'SELECT') === 0) {
                $jumlah++;
            }
        };
        Events::on('DBQuery', $pencatat);

        try {
            $aksi();
        } finally {
            Events::removeListener('DBQuery', $pencatat);
        }

        return $jumlah;
    }
}
