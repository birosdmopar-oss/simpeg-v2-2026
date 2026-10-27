<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\TukinData\DataTukinBelumTersedia;
use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\SumberCutiTbBelumTersedia;
use App\Libraries\Presensi\TukinData\SumberHarianTukin;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5A §7.7.6 — AC-TK.5 (bagian harian): presensi `dh_online` per tanggal tanpa saring `sumber` (rekap legacy
 * `L_presensi.php:12376-12391`, `:12850-12851`), konket `absen_ijin` status V & `affect_tukin` 1 per tanggal
 * (`:12343-12373`), cuti/TB lewat `SumberCutiTbInterface` (5A fail-closed).
 *
 * @internal
 */
final class HarianTukinAdapterTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    public function testKonketDisetujuiBerpengaruhTukinPerTanggal(): void
    {
        $nip = $this->buatPegawai();
        $this->konket($nip, '2026-03-10 08:00:00', '2026-03-12 17:00:00', 'V', 1, 5);
        $this->konket($nip, '2026-03-20 00:00:00', '2026-03-20 23:59:59', 'V', 1, 13);
        $this->konket($nip, '2026-03-23 00:00:00', '2026-03-23 00:00:00', 'W', 1, 5); // menunggu
        $this->konket($nip, '2026-03-24 00:00:00', '2026-03-24 00:00:00', 'X', 1, 5); // ditolak
        $this->konket($nip, '2026-03-25 00:00:00', '2026-03-25 00:00:00', 'V', 2, 5); // tidak berpengaruh tukin
        $this->konket($this->buatPegawai(), '2026-03-26 00:00:00', '2026-03-26 00:00:00', 'V', 1, 5); // pegawai lain

        $konket = $this->sumber()->konket($nip, '2026-03-01', '2026-03-31');

        $this->assertSame(['2026-03-10', '2026-03-11', '2026-03-12', '2026-03-20'], array_keys($konket));
        $this->assertSame(['affect_tukin' => 1, 'kategori' => 13], $konket['2026-03-20']);
    }

    public function testKonketDipotongRentangDanBarisTerakhirMenang(): void
    {
        $nip = $this->buatPegawai();
        $this->konket($nip, '2026-02-27 00:00:00', '2026-03-02 00:00:00', 'V', 1, 5);
        $this->konket($nip, '2026-03-02 00:00:00', '2026-03-03 00:00:00', 'V', 1, 13); // date_start lebih akhir → menang di 03-02

        $konket = $this->sumber()->konket($nip, '2026-03-01', '2026-03-31');

        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_keys($konket), 'di luar rentang dipotong');
        $this->assertSame(5, $konket['2026-03-01']['kategori']);
        $this->assertSame(13, $konket['2026-03-02']['kategori']);
    }

    public function testCutiTbDigabungDariSumber(): void
    {
        $nip = $this->buatPegawai();
        $this->konket($nip, '2026-03-10 00:00:00', '2026-03-10 00:00:00', 'V', 1, 5);

        $harian = (new SumberHarianTukin($this->db, $this->sumberCutiTbPalsu([
            '2026-03-10' => ['cuti' => ['jenis' => 'tahunan']],
            '2026-03-11' => ['tugas_belajar' => true, 'lain' => 'diabaikan'],
            '2026-04-30' => ['cuti' => ['jenis' => 'tahunan']], // di luar rentang
        ])))->untuk($nip, '2026-03-01', '2026-03-31');

        $this->assertSame([
            '2026-03-10' => ['konket' => ['affect_tukin' => 1, 'kategori' => 5], 'cuti' => ['jenis' => 'tahunan']],
            '2026-03-11' => ['tugas_belajar' => true],
        ], $harian);
    }

    public function testPresensiDhOnlinePerTanggalSemuaSumber(): void
    {
        $nip = $this->buatPegawai();
        $this->presensi($nip, '2026-03-09', '07:25:00', '16:05:00', 1);  // online
        $this->presensi($nip, '2026-03-10', '08:10:00', null, 2);        // manual, tanpa jam pulang
        $this->presensi($nip, '2026-03-11', null, '16:00:00', null);     // impor legacy
        $this->presensi($nip, '2026-02-28', '07:30:00', '16:00:00', 1);  // di luar rentang
        $this->presensi($this->buatPegawai(), '2026-03-09', '07:30:00', '16:00:00', 1); // pegawai lain

        $presensi = $this->sumber()->presensi($nip, '2026-03-01', '2026-03-31');

        $this->assertSame([
            '2026-03-09' => ['masuk' => '07:25:00', 'pulang' => '16:05:00'],
            '2026-03-10' => ['masuk' => '08:10:00', 'pulang' => null],
            '2026-03-11' => ['masuk' => null, 'pulang' => '16:00:00'],
        ], $presensi);
    }

    public function testHarianMenggabungPresensiKonketCutiTb(): void
    {
        $nip = $this->buatPegawai();
        $this->presensi($nip, '2026-03-10', '07:30:00', '16:00:00', 1);
        $this->presensi($nip, '2026-03-12', '07:45:00', '16:00:00', 2);
        $this->konket($nip, '2026-03-10 00:00:00', '2026-03-10 00:00:00', 'V', 1, 13);

        $harian = (new SumberHarianTukin($this->db, $this->sumberCutiTbPalsu([
            '2026-03-11' => ['cuti' => ['jenis' => 'tahunan']],
        ])))->untuk($nip, '2026-03-01', '2026-03-31');

        $this->assertSame([
            '2026-03-10' => ['presensi' => ['masuk' => '07:30:00', 'pulang' => '16:00:00'], 'konket' => ['affect_tukin' => 1, 'kategori' => 13]],
            '2026-03-11' => ['cuti' => ['jenis' => 'tahunan']],
            '2026-03-12' => ['presensi' => ['masuk' => '07:45:00', 'pulang' => '16:00:00']],
        ], $harian);
    }

    public function testJamPresensiTidakSahGagalTerkendali(): void
    {
        $nip = $this->buatPegawai();
        $this->presensi($nip, '2026-03-10', '24:15:00', null, null); // TIME menerima > 23:59:59; impor legacy tidak dijamin bersih

        $this->expectException(DataTukinTidakSah::class);
        $this->expectExceptionMessage("Jam presensi pegawai {$nip} tanggal 2026-03-10 tidak sah.");
        $this->sumber()->untuk($nip, '2026-03-01', '2026-03-31');
    }

    public function testSumberCutiTbBawaanFailClosed(): void
    {
        $nip = $this->buatPegawai();

        $this->expectException(DataTukinBelumTersedia::class);
        $this->expectExceptionMessage('Fase 5B');
        (new SumberHarianTukin($this->db, new SumberCutiTbBelumTersedia()))->untuk($nip, '2026-03-01', '2026-03-31');
    }

    private function sumber(): SumberHarianTukin
    {
        return new SumberHarianTukin($this->db, $this->sumberCutiTbPalsu());
    }

    private function presensi(string $nip, string $tgl, ?string $masuk, ?string $pulang, ?int $sumber): void
    {
        $this->db->table('dh_online')->insert([
            'nip' => $nip, 'tgl' => $tgl, 'jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'sumber' => $sumber,
        ]);
    }

    private function konket(string $nip, string $mulai, string $selesai, string $status, int $affect, int $kategori): void
    {
        $this->db->table('absen_ijin')->insert([
            'nip'    => $nip, 'date_start' => $mulai, 'date_end' => $selesai, 'kategori' => $kategori, 'alasan' => 'Uji',
            'status' => $status, 'affect_tukin' => $affect,
        ]);
    }
}
