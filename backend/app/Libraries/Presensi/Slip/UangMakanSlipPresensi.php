<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\HariLiburService;
use App\Libraries\Presensi\Rekap\HariUangMakan;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\Tukin\HariKerja;
use Closure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Nilai uang makan slip gaji bulan kehadiran N−1 (D-09a, MAKE-040, PRD 5B §7.2) — implementasi asli
 * {@see UangMakanSlipInterface} yang dipilih `Config\Presensi::$sumberUangMakan`, menutup blocker go-live F5-1. Port
 * `Lsl_gaji::getUangMakan` + `_getTarifUangMakan` legacy `0cf7e67` (`Lsl_gaji.php:260-414`) [K]; aturan snapshot
 * (kapan ditulis/dipertahankan) tetap di `SlipGajiService` 5A dan tidak diubah.
 *
 * - Tarif dari {@see TarifUangMakan}; tarif ≤ 0 → `UangMakanSlip(0, 0, 0)` tanpa menghitung hari (legacy :268-271).
 * - Hari dibayar = aturan murni {@see HariUangMakan} (F5B-2: sama untuk rekap uang makan) atas data bulan kalender
 *   kehadiran, dipotong hari ini WIB (disuntik).
 * - Total = {@see TarifUangMakan::totalSen()} (tarif × hari dibulatkan setengah-menjauhi-nol ke sen seperti kolom
 *   DECIMAL(14,2), keputusan Horii 28-10-2026: ikut legacy, tanpa galat/`null` untuk tarif `gol_pppk` tak bulat).
 *   `tarifSen` = tarif dibulatkan ke sen, hanya informasi: total tidak selalu sama dengan hari × `tarifSen`.
 * - `null` hanya bila pegawai tidak ada.
 *
 * Pemuat data {@see self::hariDibayarBanyak()} memakai jumlah kueri tetap per {@see self::BATCH_NIP} NIP (dipakai ulang
 * rekap uang makan D-09b). Sumber TB = baris `riwayat_tb` (status 1, `tgl_mulai` ≤ akhir bulan, `tgl_akhir` atau
 * `tgl_akhir_perpan` ≥ awal bulan) + `riwayat_tb_perpan` [K :322-344], bukan `rentangBebasPresensi`, karena aturan 25%/0
 * butuh `tgl_mulai` per TB. Libur = `HariLiburService::tanggalLibur` (aktif).
 */
final class UangMakanSlipPresensi implements UangMakanSlipInterface
{
    public const BATCH_NIP = 500;

    private readonly BaseConnection $db;

    private readonly TarifUangMakan $tarif;

    private readonly HariLiburService $hariLibur;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam instan sekarang (bawaan jam server); disuntik test
     */
    public function __construct(?BaseConnection $db = null, ?TarifUangMakan $tarif = null, ?HariLiburService $hariLibur = null, ?Closure $jam = null)
    {
        $this->db        = $db ?? db_connect();
        $this->tarif     = $tarif ?? new TarifUangMakan($this->db);
        $this->hariLibur = $hariLibur ?? service('hariLiburService');
        $this->jam       = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function hitung(string $nip, PeriodeSlip $periodeKehadiran): ?UangMakanSlip
    {
        if ($this->db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            return null;
        }

        $tarif    = $this->tarif->tarifRupiah($nip, $periodeKehadiran);
        $tarifSen = TarifUangMakan::totalSen($tarif, 1);

        if ($tarif === '0') {
            return new UangMakanSlip(0, 0, 0);
        }

        $hari = count($this->hariDibayarBanyak([$nip], $periodeKehadiran->tahun, $periodeKehadiran->bulan)[$nip] ?? []);

        return new UangMakanSlip(TarifUangMakan::totalSen($tarif, $hari), $hari, $tarifSen);
    }

    /**
     * Tanggal dibayar uang makan banyak pegawai × satu bulan kalender kehadiran (aturan {@see HariUangMakan}).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<string>> setiap NIP masukan menjadi kunci
     */
    public function hariDibayarBanyak(array $nip, int $tahun, int $bulan): array
    {
        $unik = array_values(array_unique(array_map('strval', $nip)));

        if ($unik === []) {
            return [];
        }

        $awal    = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir   = HariKerja::kemarin(TanggalBisnis::tambahBulan($awal, 1));
        $hariIni = TanggalBisnis::hariIni(($this->jam)());
        $libur   = $this->hariLibur->tanggalLibur($awal, $akhir);
        $hasil   = [];

        foreach (array_chunk($unik, self::BATCH_NIP) as $batch) {
            $cuti     = $this->cuti($batch, $awal, $akhir);
            $tb       = $this->tugasBelajar($batch, $awal, $akhir);
            $konket   = $this->konket($batch, $awal, $akhir);
            $presensi = $this->presensi($batch, $awal, $akhir);

            foreach ($batch as $n) {
                $hasil[$n] = HariUangMakan::hariDibayar($tahun, $bulan, $hariIni, $libur, $cuti[$n] ?? [], $tb[$n] ?? [], $konket[$n] ?? [], $presensi[$n] ?? []);
            }
        }

        return $hasil;
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, list<array{0: string, 1: string}>>
     */
    private function cuti(array $nip, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($this->db->table('riwayat_cuti')
            ->select(['nip', 'tgl_mulai', 'tgl_akhir'])
            ->whereIn('nip', $nip)
            ->where('status', 1)
            ->where('tgl_mulai <=', $akhir)
            ->where('tgl_akhir >=', $awal)
            ->get()->getResultArray() as $b) {
            $hasil[(string) $b['nip']][] = [(string) $b['tgl_mulai'], (string) $b['tgl_akhir']];
        }

        return $hasil;
    }

    /**
     * Segmen TB per NIP: baris induk urut `tgl_mulai` naik, lalu baris perpanjangan urut `tgl_mulai_perpan`,
     * `tgl_akhir_perpan` naik [K :322-344] (urutan ini menentukan pemilik tanggal di `HariUangMakan`).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<array{id: int, mulai_induk: string, dari: string, sampai: string}>>
     */
    private function tugasBelajar(array $nip, string $awal, string $akhir): array
    {
        $induk = $this->db->table('riwayat_tb')
            ->select(['id_riwayat_tb', 'nip', 'tgl_mulai', 'tgl_akhir'])
            ->whereIn('nip', $nip)
            ->where('status', 1)
            ->where('tgl_mulai <=', $akhir)
            ->groupStart()
            ->where('tgl_akhir >=', $awal)
            ->orGroupStart()->where('tgl_akhir_perpan IS NOT NULL', null, false)->where('tgl_akhir_perpan >=', $awal)->groupEnd()
            ->groupEnd()
            ->orderBy('nip', 'ASC')->orderBy('tgl_mulai', 'ASC')->orderBy('id_riwayat_tb', 'ASC')
            ->get()->getResultArray();

        $hasil = [];
        $mulai = [];
        $milik = [];

        foreach ($induk as $b) {
            $id         = (int) $b['id_riwayat_tb'];
            $mulai[$id] = (string) $b['tgl_mulai'];
            $milik[$id] = (string) $b['nip'];

            $hasil[$milik[$id]][] = ['id' => $id, 'mulai_induk' => $mulai[$id], 'dari' => $mulai[$id], 'sampai' => (string) $b['tgl_akhir']];
        }

        if ($mulai !== []) {
            foreach ($this->db->table('riwayat_tb_perpan')
                ->select(['id_riwayat_tb', 'tgl_mulai_perpan', 'tgl_akhir_perpan'])
                ->whereIn('id_riwayat_tb', array_keys($mulai))
                ->orderBy('tgl_mulai_perpan', 'ASC')->orderBy('tgl_akhir_perpan', 'ASC')->orderBy('id_riwayat_tb_perpan', 'ASC')
                ->get()->getResultArray() as $p) {
                $id = (int) $p['id_riwayat_tb'];

                $hasil[$milik[$id]][] = ['id' => $id, 'mulai_induk' => $mulai[$id], 'dari' => (string) $p['tgl_mulai_perpan'], 'sampai' => (string) $p['tgl_akhir_perpan']];
            }
        }

        return $hasil;
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, list<array{mulai: string, akhir: string, affect_tukin: int|string, kategori: int|string}>>
     */
    private function konket(array $nip, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($this->db->table('absen_ijin')
            ->select(['nip', 'date_start', 'date_end', 'affect_tukin', 'kategori'])
            ->whereIn('nip', $nip)
            ->where('status', 'V')
            ->where('DATE(date_start) <=', $akhir)
            ->where('DATE(date_end) >=', $awal)
            ->orderBy('nip', 'ASC')->orderBy('date_start', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray() as $b) {
            $hasil[(string) $b['nip']][] = [
                'mulai'        => (string) $b['date_start'], 'akhir' => (string) $b['date_end'],
                'affect_tukin' => (int) $b['affect_tukin'], 'kategori' => (int) $b['kategori'],
            ];
        }

        return $hasil;
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, array<string, array{masuk: ?string, pulang: ?string}>>
     */
    private function presensi(array $nip, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($this->db->table('dh_online')
            ->select(['nip', 'tgl', 'jam_masuk', 'jam_pulang'])
            ->whereIn('nip', $nip)
            ->where('tgl >=', $awal)
            ->where('tgl <=', $akhir)
            ->get()->getResultArray() as $b) {
            $hasil[(string) $b['nip']][(string) $b['tgl']] = [
                'masuk'  => $b['jam_masuk'] === null ? null : (string) $b['jam_masuk'],
                'pulang' => $b['jam_pulang'] === null ? null : (string) $b['jam_pulang'],
            ];
        }

        return $hasil;
    }
}
