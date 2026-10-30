<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * `DataPegawaiTukin` dari data v2 (PRD 5A §7.7.5) mengikuti rekap legacy `laporan_tukin_us_skp` [K]:
 *
 * - `predikatSkp` = `riwayat_skp_periodik.hasil_akhir` (huruf kecil) baris `status = 1`, `periode_id` = id baris
 *   `bkn_periode_ekinper` bulan itu, `hasil_akhir IS NOT NULL` (`L_presensi.php:12397-12401`); tanpa baris BKN atau
 *   tanpa data → null.
 * - `jumlahAnak` = jumlah `detail_anak` ber-`tgl_lahir` ≤ akhir periode milik `riwayat_keluarga` `status = 1` dengan
 *   `tgl_perkawinan` ≤ akhir periode (:12170-12198).
 * - `tmtMasuk` (pegawai baru; keputusan reviewer CR 20-10-2026, rincian di README folder ini):
 *   1. `gol_ruang`/`tmtsk_pangkat` [K `L_employee.php:6439`, `:6453`, `:6555-6560`]: `riwayat_kp` `status = 1` terakhir
 *      (`tmtsk DESC`) dengan `tmtsk` ≤ awal periode; bila tidak ada dan pegawai dibuat setelah akhir periode, batasnya
 *      ≤ akhir periode;
 *   2. PPPK (`id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK`) [K `:6580-6584`]: nilai di atas ditimpa `gol_pppk`/`tmtsk`
 *      `riwayat_mutasi_jabatan` `status = 1`, `jenis_jabatan = 1` terakhir dengan `tmtsk` ≤ akhir periode
 *      (`:6478-6482`);
 *   3. rekap [K `L_presensi.php:12703-12716`]: `gol_ruang` lebih dari 5 karakter diawali "CPNS" → `tmtsk_pangkat`; PPPK →
 *      `tmtsk_pangkat` bila setelah hari kerja pertama periode (`$firstDayHK_CM`, via `HariKerja`); selain itu null.
 *      [V2] cabang PPPK rekap mengenali PPPK lewat id (legacy :12707 lewat nama `jenis_pegawai == 'PPPK'`): nama master
 *      dapat diubah lewat CRUD, cek nama bisa gagal diam-diam.
 *   4. [K T-02, CR-083, keputusan Horii 29-10] CPNS atau PPPK dengan `tmtsk_pangkat` kosong (`:12703-12710`) →
 *      `lewatiLoopHarian` (seluruh hari loop harian dilewati; rincian di {@see DataPegawaiTukin}); PPPK hanya bila
 *      periode punya hari kerja.
 * - `tmtKeluar` = `pegawai.tmt_status` bila `pegawai.status = 2` [V2 README Tukin].
 *
 * Varian massal {@see self::untukBanyak()} (MAKE-039, keputusan reviewer CR M0 F1 — deviasi aditif PRD 5B §3.3): aturan
 * yang sama (helper bersama {@see self::pegawaiBaru()}), jumlah kueri tetap per {@see self::BATCH_NIP} NIP
 * (`pegawai`, `riwayat_skp_periodik`, `detail_anak`, `riwayat_kp`, dan `riwayat_mutasi_jabatan` bila ada PPPK); galat satu
 * pegawai menjadi {@see GalatDataTukin} NIP itu saja.
 */
final class SumberDataPegawaiTukin
{
    public const BATCH_NIP = 500;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $hariLibur        dipakai menentukan hari kerja pertama periode (PPPK)
     * @param bool         $tanpaPegawaiBaru rekap IL [K, CR-083, keputusan Horii 30-10]: `laporan_tukin_us_skp_il`
     *                                       (`L_presensi.php:13494-15452`) tidak punya blok pegawai baru, jadi `tmtMasuk`
     *                                       null dan `lewatiLoopHarian` false; juga tanpa `tmtKeluar` (pegawai keluar IL
     *                                       ikut legacy, keputusan Horii 30-10 — {@see self::keluarTanpaDataIl()})
     * @param string|null  $batasAnak        batas `tgl_lahir`/`tgl_perkawinan` jumlah anak; bawaan akhir periode. Rekap IL
     *                                       memakai `de_tukin` = min(akhir periode, hari ini) (`L_presensi.php:13840, :13851`)
     */
    public function untuk(string $nip, PeriodeTukin $periode, ?string $idPeriodeBkn, array $hariLibur = [], bool $tanpaPegawaiBaru = false, ?string $batasAnak = null): DataPegawaiTukin
    {
        $pegawai = $this->db->table('pegawai')
            ->select(['status', 'tmt_status', 'id_jenis_pegawai', 'created_at'])
            ->where('nip', $nip)
            ->get()
            ->getRowArray();

        if (! is_array($pegawai)) {
            throw new DataTukinTidakSah("Pegawai {$nip} tidak ditemukan.");
        }

        try {
            $baru = $tanpaPegawaiBaru ? ['tmtMasuk' => null, 'lewatiLoopHarian' => false] : $this->pegawaiBaruDariDb($nip, $pegawai, $periode, $hariLibur);

            return new DataPegawaiTukin(
                $this->predikatSkp($nip, $idPeriodeBkn),
                $this->jumlahAnak($nip, $batasAnak ?? $periode->akhir),
                $baru['tmtMasuk'],
                ! $tanpaPegawaiBaru && (int) $pegawai['status'] === 2 && $pegawai['tmt_status'] !== null ? (string) $pegawai['tmt_status'] : null,
                $baru['lewatiLoopHarian'],
            );
        } catch (InvalidArgumentException $e) {
            throw new DataTukinTidakSah("Data pegawai {$nip} untuk Tukin tidak sah: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * `DataPegawaiTukin` banyak pegawai; hasil per NIP = {@see self::untuk()} NIP itu, atau galatnya.
     *
     * @param list<string> $nip
     * @param list<string> $hariLibur
     *
     * @return array<array-key, DataPegawaiTukin|GalatDataTukin> setiap NIP masukan menjadi kunci
     */
    public function untukBanyak(array $nip, PeriodeTukin $periode, ?string $idPeriodeBkn, array $hariLibur = [], bool $tanpaPegawaiBaru = false, ?string $batasAnak = null): array
    {
        $unik  = array_values(array_unique(array_map('strval', $nip)));
        $hasil = [];

        foreach (array_chunk($unik, self::BATCH_NIP) as $batch) {
            $pegawai = [];

            foreach ($this->db->table('pegawai')->select(['nip', 'status', 'tmt_status', 'id_jenis_pegawai', 'created_at'])->whereIn('nip', $batch)->get()->getResultArray() as $p) {
                $pegawai[(string) $p['nip']] = $p;
            }

            $skp  = $this->predikatSkpBanyak($batch, $idPeriodeBkn);
            $anak = $this->jumlahAnakBanyak($batch, $batasAnak ?? $periode->akhir);
            $kp   = $this->kelompokkan($this->db->table('riwayat_kp')
                ->select(['nip', 'gol_ruang', 'tmtsk'])
                ->whereIn('nip', $batch)
                ->where('status', 1)
                ->where('tmtsk <=', max($periode->awal, $periode->akhir))
                ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')->orderBy('id_riwayat_kp', 'DESC')
                ->get()->getResultArray());

            $pppk   = array_values(array_filter($batch, static fn (string $n): bool => isset($pegawai[$n]) && (int) ($pegawai[$n]['id_jenis_pegawai'] ?? 0) === Jabatan::JENIS_PEGAWAI_PPPK));
            $mutasi = $pppk === [] ? [] : $this->kelompokkan($this->db->table('riwayat_mutasi_jabatan')
                ->select(['nip', 'gol_pppk', 'tmtsk'])
                ->whereIn('nip', $pppk)
                ->where('status', 1)
                ->where('jenis_jabatan', 1)
                ->where('tmtsk <=', $periode->akhir)
                ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')->orderBy('id_riwayat_mutasi_jabatan', 'DESC')
                ->get()->getResultArray());

            foreach ($batch as $n) {
                if (! isset($pegawai[$n])) {
                    $hasil[$n] = new DataTukinTidakSah("Pegawai {$n} tidak ditemukan.");

                    continue;
                }

                $p                = $pegawai[$n];
                [$golRuang, $tmt] = self::pangkatDari($kp[$n] ?? [], $periode, (string) ($p['created_at'] ?? ''));

                try {
                    $baru      = $tanpaPegawaiBaru ? ['tmtMasuk' => null, 'lewatiLoopHarian' => false] : self::pegawaiBaru($golRuang, $tmt, (int) ($p['id_jenis_pegawai'] ?? 0) === Jabatan::JENIS_PEGAWAI_PPPK, $mutasi[$n][0] ?? null, $periode, $hariLibur);
                    $hasil[$n] = new DataPegawaiTukin(
                        $skp[$n] ?? null,
                        $anak[$n] ?? 0,
                        $baru['tmtMasuk'],
                        ! $tanpaPegawaiBaru && (int) $p['status'] === 2 && $p['tmt_status'] !== null ? (string) $p['tmt_status'] : null,
                        $baru['lewatiLoopHarian'],
                    );
                } catch (InvalidArgumentException $e) {
                    $hasil[$n] = new DataTukinTidakSah("Data pegawai {$n} untuk Tukin tidak sah: " . $e->getMessage(), 0, $e);
                }
            }
        }

        return $hasil;
    }

    public function predikatSkp(string $nip, ?string $idPeriodeBkn): ?string
    {
        if ($idPeriodeBkn === null) {
            return null;
        }

        $baris = $this->db->table('riwayat_skp_periodik')
            ->select('hasil_akhir')
            ->where('nip', $nip)
            ->where('status', 1)
            ->where('periode_id', $idPeriodeBkn)
            ->where('hasil_akhir IS NOT NULL', null, false)
            ->orderBy('id_riwayat_skp_periodik', 'ASC')
            ->get(1)
            ->getRowArray();

        return is_array($baris) ? strtolower((string) $baris['hasil_akhir']) : null;
    }

    public function jumlahAnak(string $nip, string $akhir): int
    {
        $baris = $this->db->table('detail_anak da')
            ->select('COUNT(*) AS jumlah')
            ->join('riwayat_keluarga rk', 'rk.id_riwayat_keluarga = da.id_riwayat_keluarga')
            ->where('rk.nip', $nip)
            ->where('rk.status', 1)
            ->where('rk.tgl_perkawinan <=', $akhir)
            ->where('da.tgl_lahir <=', $akhir)
            ->get()
            ->getRowArray();

        return (int) ($baris['jumlah'] ?? 0);
    }

    /**
     * @param array<string, mixed> $pegawai baris `pegawai` (id_jenis_pegawai, created_at)
     * @param list<string>         $hariLibur
     */
    public function tmtMasuk(string $nip, array $pegawai, PeriodeTukin $periode, array $hariLibur): ?string
    {
        return $this->pegawaiBaruDariDb($nip, $pegawai, $periode, $hariLibur)['tmtMasuk'];
    }

    /**
     * @param array<string, mixed> $pegawai baris `pegawai` (id_jenis_pegawai, created_at)
     * @param list<string>         $hariLibur
     *
     * @return array{tmtMasuk: string|null, lewatiLoopHarian: bool}
     */
    private function pegawaiBaruDariDb(string $nip, array $pegawai, PeriodeTukin $periode, array $hariLibur): array
    {
        [$golRuang, $tmt] = $this->pangkat($nip, $periode, (string) ($pegawai['created_at'] ?? ''));

        $pppk    = (int) ($pegawai['id_jenis_pegawai'] ?? 0) === Jabatan::JENIS_PEGAWAI_PPPK;
        $jabatan = null;

        if ($pppk) {
            $jabatan = $this->db->table('riwayat_mutasi_jabatan')
                ->select(['gol_pppk', 'tmtsk'])
                ->where('nip', $nip)
                ->where('status', 1)
                ->where('jenis_jabatan', 1)
                ->where('tmtsk <=', $periode->akhir)
                ->orderBy('tmtsk', 'DESC')
                ->orderBy('id_riwayat_mutasi_jabatan', 'DESC')
                ->get(1)
                ->getRowArray();
        }

        return self::pegawaiBaru($golRuang, $tmt, $pppk, is_array($jabatan) ? $jabatan : null, $periode, $hariLibur);
    }

    /**
     * Aturan pegawai baru bersama jalur tunggal & massal (lihat docblock kelas butir 1–4): `tmtMasuk`, dan
     * `lewatiLoopHarian` untuk CPNS atau PPPK tanpa TMT (butir 4). Publik agar cabang CPNS (T-02) dapat diuji langsung:
     * `riwayat_kp.tmtsk` `NOT NULL` dan disaring `tmtsk <=` (legacy sama, `L_employee.php:6439`), sehingga lewat basis
     * data `gol_ruang` CPNS selalu membawa TMT. PPPK tanpa TMT terbentuk bila tidak ada baris `riwayat_mutasi_jabatan`
     * maupun `riwayat_kp` yang memberi TMT.
     *
     * @param array<string, mixed>|null $jabatanPppk baris `riwayat_mutasi_jabatan` PPPK terakhir (`gol_pppk`, `tmtsk`)
     * @param list<string>              $hariLibur
     *
     * @return array{tmtMasuk: string|null, lewatiLoopHarian: bool}
     */
    public static function pegawaiBaru(string $golRuang, string $tmt, bool $pppk, ?array $jabatanPppk, PeriodeTukin $periode, array $hariLibur): array
    {
        if ($pppk && $jabatanPppk !== null) {
            $golRuang = (string) ($jabatanPppk['gol_pppk'] ?? '');
            $tmt      = (string) ($jabatanPppk['tmtsk'] ?? '');
        }

        if (strlen($golRuang) > 5 && strtoupper(substr($golRuang, 0, 4)) === 'CPNS') {
            return $tmt !== '' ? ['tmtMasuk' => $tmt, 'lewatiLoopHarian' => false] : ['tmtMasuk' => null, 'lewatiLoopHarian' => true];
        }

        if ($pppk) {
            $pertama = HariKerja::daftar($periode, $hariLibur)[0] ?? null;

            // PPPK tanpa TMT [K, CR-083, keputusan Horii 29-10]: `new DateTime('')` = saat ekspor > hari kerja pertama
            // (:12707-12710) → `$dtTmtPegBaru` = saat ekspor → seluruh hari loop harian dilewati (mekanisme T-02). Periode
            // tanpa hari kerja: legacy `$firstDayHK_CM` kosong juga = saat ekspor (dibuat sesudahnya, tidak "lebih kecil"),
            // dan loop tidak punya hari kerja, jadi tanda tidak dipasang.
            if ($tmt === '') {
                return ['tmtMasuk' => null, 'lewatiLoopHarian' => $pertama !== null];
            }

            return ['tmtMasuk' => $pertama !== null && $tmt > $pertama ? $tmt : null, 'lewatiLoopHarian' => false];
        }

        return ['tmtMasuk' => null, 'lewatiLoopHarian' => false];
    }

    /**
     * Rekap IL [K, CR-085, keputusan Horii 30-10]: pegawai keluar (`status = 2`) yang data hariannya tidak dimuat legacy —
     * cuti/TB/konket/`dh_online` hanya dimuat bila `status = 1` atau `tmt_status > de_tukin` (`L_presensi.php:13906`,
     * `:13957`, `:14045`, `:14084`; `de_tukin` = akhir periode dipotong ke hari ini, `:13640`). `tmt_status` kolom DATE
     * dibanding string tanggal → perbandingan tanggal murni (tanpa zona waktu); NULL tidak lolos `>` sehingga juga tanpa
     * data. Populasi IL tetap `tmt_status > awal periode` (`L_employee.php:6633`), jadi pegawai ini masuk rekap dengan
     * semua hari kerja TK, potongan cuti 0, SKP tetap dibaca (`:14110`). Hasil legacy bergantung pada tanggal ekspor.
     *
     * @param list<string> $nip
     *
     * @return list<string>
     */
    public function keluarTanpaDataIl(array $nip, string $deTukin): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $nip))), self::BATCH_NIP) as $batch) {
            foreach ($this->db->table('pegawai')
                ->select('nip')
                ->whereIn('nip', $batch)
                ->where('status', 2)
                ->groupStart()->where('tmt_status IS NULL', null, false)->orWhere('tmt_status <=', $deTukin)->groupEnd()
                ->get()->getResultArray() as $b) {
                $hasil[] = (string) $b['nip'];
            }
        }

        return $hasil;
    }

    /**
     * NIP yang data hariannya (cuti, TB, konket, presensi) tidak dimuat [K T-01, CR-083, keputusan Horii 29-10]: legacy
     * memuat keempatnya hanya bila `peg.created_at < '<akhir periode BKN>'` (`L_presensi.php:12209`, `:12283`, `:12346`,
     * `:12383`) — DATETIME dibanding string tanggal (= tanggal itu pukul 00:00:00 jam dinding WIB), sehingga pegawai yang
     * dibuat pada hari terakhir periode (WIB, pukul berapa pun) tidak punya data harian. Populasi tetap
     * `DATE(created_at) <= akhir` (`L_employee.php:6414`), jadi pegawai itu masuk rekap dengan semua hari kerja TK.
     * Saringan di sini dibatasi pada irisan keduanya (hari terakhir saja): pegawai yang dibuat setelah akhir periode tidak
     * masuk populasi rekap legacy, sehingga laporan per pegawai v2 (yang memakai kalkulasi rekap, K-1) untuknya tidak
     * diubah. `pegawai.created_at` v2 `NOT NULL` dan berisi UTC: hari terakhir WIB = rentang UTC {@see StempelUtc}
     * (keputusan Horii 30-10; bergantung GAP-2).
     *
     * @param list<string> $nip
     *
     * @return list<string>
     */
    public function tanpaDataHarian(array $nip, PeriodeTukin $periode): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $nip))), self::BATCH_NIP) as $batch) {
            foreach ($this->db->table('pegawai')
                ->select('nip')
                ->whereIn('nip', $batch)
                ->where('created_at >=', StempelUtc::awalHari($periode->akhir))
                ->where('created_at <', StempelUtc::awalHariBerikut($periode->akhir))
                ->get()->getResultArray() as $b) {
                $hasil[] = (string) $b['nip'];
            }
        }

        return $hasil;
    }

    /**
     * {@see self::pangkat()} dari baris `riwayat_kp` satu pegawai yang sudah dimuat (urut `tmtsk`, `id` turun).
     *
     * @param list<array<string, mixed>> $baris
     *
     * @return array{0: string, 1: string}
     */
    private static function pangkatDari(array $baris, PeriodeTukin $periode, string $createdAt): array
    {
        $batas = [$periode->awal];

        // Legacy `$dtCreatedAt > "akhir 23:59:59"` (L_employee.php:6429-6433) jam dinding WIB; `created_at` v2 = UTC (CR-083).
        if ($createdAt !== '' && $createdAt >= StempelUtc::awalHariBerikut($periode->akhir)) {
            $batas[] = $periode->akhir;
        }

        foreach ($batas as $tanggal) {
            foreach ($baris as $kp) {
                if ((string) $kp['tmtsk'] <= $tanggal) {
                    return [(string) ($kp['gol_ruang'] ?? ''), (string) ($kp['tmtsk'] ?? '')];
                }
            }
        }

        return ['', ''];
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, string>
     */
    private function predikatSkpBanyak(array $nip, ?string $idPeriodeBkn): array
    {
        if ($idPeriodeBkn === null) {
            return [];
        }

        $hasil = [];

        foreach ($this->db->table('riwayat_skp_periodik')
            ->select(['nip', 'hasil_akhir'])
            ->whereIn('nip', $nip)
            ->where('status', 1)
            ->where('periode_id', $idPeriodeBkn)
            ->where('hasil_akhir IS NOT NULL', null, false)
            ->orderBy('nip', 'ASC')
            ->orderBy('id_riwayat_skp_periodik', 'ASC')
            ->get()->getResultArray() as $b) {
            $hasil[(string) $b['nip']] ??= strtolower((string) $b['hasil_akhir']);
        }

        return $hasil;
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, int>
     */
    private function jumlahAnakBanyak(array $nip, string $akhir): array
    {
        $hasil = [];

        foreach ($this->db->table('detail_anak da')
            ->select('rk.nip, COUNT(*) AS jumlah')
            ->join('riwayat_keluarga rk', 'rk.id_riwayat_keluarga = da.id_riwayat_keluarga')
            ->whereIn('rk.nip', $nip)
            ->where('rk.status', 1)
            ->where('rk.tgl_perkawinan <=', $akhir)
            ->where('da.tgl_lahir <=', $akhir)
            ->groupBy('rk.nip')
            ->get()->getResultArray() as $b) {
            $hasil[(string) $b['nip']] = (int) $b['jumlah'];
        }

        return $hasil;
    }

    /**
     * @param list<array<string, mixed>> $baris
     *
     * @return array<array-key, list<array<string, mixed>>>
     */
    private function kelompokkan(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $b) {
            $hasil[(string) $b['nip']][] = $b;
        }

        return $hasil;
    }

    /**
     * `gol_ruang`/`tmtsk` `riwayat_kp` terakhir [K `L_employee.php:6439`, `:6453`].
     *
     * @return array{0: string, 1: string}
     */
    private function pangkat(string $nip, PeriodeTukin $periode, string $createdAt): array
    {
        $batas = [$periode->awal];

        // Pegawai dibuat setelah akhir periode: legacy mengisi pangkat yang belum ada dengan batas akhir periode (:6453).
        // Legacy `$dtCreatedAt > "akhir 23:59:59"` (L_employee.php:6429-6433) jam dinding WIB; `created_at` v2 = UTC (CR-083).
        if ($createdAt !== '' && $createdAt >= StempelUtc::awalHariBerikut($periode->akhir)) {
            $batas[] = $periode->akhir;
        }

        foreach ($batas as $tanggal) {
            $kp = $this->db->table('riwayat_kp')
                ->select(['gol_ruang', 'tmtsk'])
                ->where('nip', $nip)
                ->where('status', 1)
                ->where('tmtsk <=', $tanggal)
                ->orderBy('tmtsk', 'DESC')
                ->orderBy('id_riwayat_kp', 'DESC')
                ->get(1)
                ->getRowArray();

            if (is_array($kp)) {
                return [(string) ($kp['gol_ruang'] ?? ''), (string) ($kp['tmtsk'] ?? '')];
            }
        }

        return ['', ''];
    }
}
