<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinDariWebConfig;
use App\Libraries\Presensi\TukinData\SumberCutiTbFase4;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use App\Libraries\Presensi\TukinData\SumberHarianTukin;
use App\Libraries\Presensi\TukinData\SumberHariLibur;
use App\Libraries\Presensi\TukinData\SumberPeriodeTukin;
use App\Libraries\Presensi\TukinData\TukinDataService;
use Config\Presensi;
use LogicException;
use stdClass;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5A §7.7.1 — `TukinDataService::siapkan()` merakit argumen `KalkulasiTukin::hitung()` (periode BKN, libur periode
 * sebelumnya s.d. akhir, harian, konfigurasi, data pegawai); rakitan bawaan memakai sumber cuti/TB Fase 4 sejak 5B M1
 * (MAKE-039; sebelumnya fail-closed 5A).
 *
 * @internal
 */
final class TukinDataServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF_UJI);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testSiapkanMerakitArgumenDanDapatDihitung(): void
    {
        $nip = $this->buatPegawai();
        $id  = $this->barisBkn(2026, 4, '03-14', '04-13');
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-02-20', 'tgl_akhir' => '2026-02-20', 'nama_libur' => 'Libur uji', 'status' => 1]);
        $this->db->table('riwayat_skp_periodik')->insert(['id_pns' => 'X', 'nip' => $nip, 'nama' => 'Uji', 'periode_id' => $id, 'hasil_akhir' => 'Baik', 'status' => 1]);
        $this->db->table('absen_ijin')->insert(['nip' => $nip, 'date_start' => '2026-03-20', 'date_end' => '2026-03-20', 'kategori' => 5, 'alasan' => 'Uji', 'status' => 'V', 'affect_tukin' => 1]);

        $argumen = $this->service()->siapkan($nip, 2026, 4);

        $this->assertSame(['2026-03-14', '2026-04-13'], [$argumen->periode->awal, $argumen->periode->akhir], 'periode BKN');
        $this->assertSame(['2026-02-20'], $argumen->hariLibur, 'libur sejak awal periode sebelumnya (16 Feb)');
        $this->assertSame(['affect_tukin' => 1, 'kategori' => 5], $argumen->harian['2026-03-20']['konket']);
        $this->assertSame('baik', $argumen->pegawai?->predikatSkp);
        $this->assertSame(50, $argumen->konfigurasi->tarif('TL1/PSW1'));

        $hasil = KalkulasiTukin::hitung($argumen->periode, $argumen->hariLibur, $argumen->harian, $argumen->konfigurasi, '2026-04-13', $argumen->pegawai);
        $this->assertSame('2026-04-13', $hasil->akhirDihitung);
    }

    public function testPresensiDhOnlineMenentukanPotonganHarian(): void
    {
        $nip = $this->buatPegawai();
        // Periode April 2026 tanpa baris BKN = 16 Maret–15 April; 16 & 17 Maret 2026 = Senin & Selasa.
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => '2026-03-16', 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:00:00', 'sumber' => 1]);

        $argumen = $this->service()->siapkan($nip, 2026, 4);
        $hasil   = KalkulasiTukin::hitung($argumen->periode, $argumen->hariLibur, $argumen->harian, $argumen->konfigurasi, '2026-03-17', $argumen->pegawai);

        $this->assertSame('hadir', $hasil->harian['2026-03-16']->status);
        $this->assertSame(0, $hasil->harian['2026-03-16']->potongan);
        $this->assertSame('TK', $hasil->harian['2026-03-17']->status, 'tanpa baris dh_online');
        $this->assertSame($argumen->konfigurasi->potonganHarian('TK'), $hasil->potonganPresensi);
    }

    public function testRakitanBawaanMemakaiSumberCutiTbFase4(): void
    {
        // 5B M1 (MAKE-039): `Config\Presensi::$sumberCutiTb` = `SumberCutiTbFase4`, rakitan bawaan tidak lagi fail-closed.
        $this->assertSame(SumberCutiTbFase4::class, config(Presensi::class)->sumberCutiTb);
        $this->assertInstanceOf(SumberCutiTbFase4::class, TukinDataService::sumberCutiTb(config(Presensi::class)));

        $nip = $this->buatPegawai();
        $this->db->table('riwayat_cuti')->insert(['nip' => $nip, 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-03-17', 'tgl_akhir' => '2026-03-17', 'lama_cuti' => 1, 'status' => 1]);

        $argumen = TukinDataService::bawaan($this->db)->siapkan($nip, 2026, 4);
        $this->assertSame('tahunan', $argumen->harian['2026-03-17']['cuti']['jenis']);
    }

    public function testSumberCutiTbHarusMengimplementasikanInterface(): void
    {
        $config               = new Presensi();
        $config->sumberCutiTb = stdClass::class;

        $this->expectException(LogicException::class);
        TukinDataService::sumberCutiTb($config);
    }

    private function service(): TukinDataService
    {
        return new TukinDataService(
            new KonfigurasiTukinDariWebConfig(service('webConfigService')),
            new SumberPeriodeTukin($this->db),
            new SumberHariLibur(service('hariLiburService')),
            new SumberDataPegawaiTukin($this->db),
            new SumberHarianTukin($this->db, $this->sumberCutiTbPalsu()),
        );
    }
}
