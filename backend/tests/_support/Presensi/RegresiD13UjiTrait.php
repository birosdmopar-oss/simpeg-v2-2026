<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Fixture suite regresi D-13 (PRD 5C §7.6, MAKE-052) untuk skenario Tukin S01–S10, S12: jalur nyata
 * `Laporan\LaporanTukinService::laporan` (per pegawai) dan `Laporan\RekapTukinService::daftar` (rekap) — keduanya
 * `KalkulasiLaporanTukin` → `TukinDataService::siapkanBanyak` (adapter 5A, `SumberCutiTbFase4`, TB Fase 4) →
 * `KalkulasiTukin::hitung`. Tarif `web_config` = `TARIF_UJI` (TL1/PSW1 0,5; TL2/PSW2 1; TL3/PSW3 1,25; TA 1,5; TK 3; faktor
 * presensi 0,2). Bulan laporan bawaan 2026-09 = periode 16 Agu – 15 Sep 2026 (22 hari kerja; Jumat 21/28 Agu, 4/11 Sep),
 * jam server disuntik 27 Okt 2026.
 *
 * Catatan CR-076 (usulan, menunggu Horii): baris hari non-kerja mungkin ditambahkan ke `harian[]` (lapisan tampilan). Test
 * suite ini **tidak** meng-assert jumlah baris `harian[]` atau ketiadaan tanggal libur di dalamnya; hari libur/akhir pekan
 * dibuktikan lewat hari kerja, rincian, dan total.
 */
trait RegresiD13UjiTrait
{
    use CutiTbUjiTrait;
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    protected int $satkerD13;

    protected function siapkanD13(): void
    {
        $this->siapkanLaporanTukin();
        $this->satkerD13 = $this->buatSatker($this->buatUnit());
    }

    protected function bersihkanD13(): void
    {
        $this->bersihkanLaporanTukin();
    }

    /**
     * Pegawai populasi rekap di satker uji yang hadir tepat waktu di semua hari kerja periode 2026-09, kecuali `$tanpa`.
     *
     * @param list<string> $tanpa tanggal tanpa baris presensi
     */
    protected function pegawaiHadirD13(array $tanpa = []): string
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->hadirSepanjangPeriode($nip);

        if ($tanpa !== []) {
            $this->db->table('dh_online')->where('nip', $nip)->whereIn('tgl', $tanpa)->delete();
        }

        return $nip;
    }

    /** Ganti/isi baris presensi satu tanggal (minimal salah satu jam terisi, CHECK `dh_online`). */
    protected function presensiD13(string $nip, string $tgl, ?string $masuk, ?string $pulang): void
    {
        $this->db->table('dh_online')->where('nip', $nip)->where('tgl', $tgl)->delete();
        $this->hadir($nip, $tgl, $masuk, $pulang);
    }

    /**
     * @return array<string, mixed>
     */
    protected function laporanD13(string $nip, string $bulan = '2026-09', string $wib = '2026-10-27 09:00:00'): array
    {
        return $this->serviceLaporanTukin($wib)->laporan($this->adminRekap(), $nip, ['bulan' => $bulan]);
    }

    /**
     * Baris harian laporan per tanggal.
     *
     * @param array<string, mixed> $laporan
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function harianD13(array $laporan): array
    {
        return array_column($laporan['harian'], null, 'tanggal');
    }

    /**
     * Baris pegawai di rekap Tukin lingkup satker uji.
     *
     * @return array<string, mixed>
     */
    protected function barisRekapD13(string $nip, string $bulan = '2026-09', string $wib = '2026-10-27 09:00:00'): array
    {
        $rekap = $this->serviceRekapTukin($wib)->daftar($this->adminRekap(), ['bulan' => $bulan, 'unit_satker' => 'sat_' . $this->satkerD13, 'per_page' => '100']);
        $baris = array_column($rekap['items'], null, 'nip')[$nip] ?? null;
        $this->assertIsArray($baris, "NIP {$nip} ada di rekap");

        return $baris;
    }

    /**
     * K-1: laporan per pegawai = baris rekap (kolom potongan & total) untuk data yang sama.
     *
     * @param array<string, mixed> $laporan
     */
    protected function assertRekapSamaLaporanD13(string $nip, array $laporan, string $bulan = '2026-09', string $wib = '2026-10-27 09:00:00'): void
    {
        $baris = $this->barisRekapD13($nip, $bulan, $wib);

        foreach (['TL', 'PSW', 'TK', 'TA', 'CUTI'] as $k) {
            $this->assertSame($baris['pengurangan'][$k], $laporan['rincian'][$k] ?? '0.00', "rekap = laporan kolom {$k}");
        }

        $this->assertSame(
            [$baris['total_presensi'], $baris['total_skp'], $baris['total_potongan']],
            [$laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']],
            'rekap = laporan total',
        );
    }
}
