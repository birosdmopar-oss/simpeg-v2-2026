<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;
use InvalidArgumentException;

/**
 * MAKE-022 Tahap 2b — kuota cuti tahunan (jenis 1) atas value object `SaldoCuti`: validasi (PRD Fase 4 v1.1 §6.3.4),
 * potong dan refund SATU algoritma (§6.3.5, D8, A-11). Murni: baris saldo, porsi (`PorsiTahunCuti`), dan jumlah
 * "menunggu" (status {0,3}, dihitung ulang dari tanggal oleh service) diterima sebagai parameter. `KuotaCutiService`
 * (M3) membungkus kelas ini dengan transaksi + `FOR UPDATE` + `audit_logs`; DILARANG ada implementasi potong/refund lain.
 *
 * - Potong (transisi ke 1 atau 4): `lcTahunIni` dari N-2 → N-1 → N tanpa normalisasi ulang (LK-05 tidak dibawa);
 *   status 1 dan `lcTahunDepan > 0` → `utang += lcTahunDepan` (≤ MAKS_UTANG); status 4 → `ditangguhkan += lcTahunIni`
 *   dan porsi tahun depan dibuang [K `L:3891-3901`]. Kurang → gagal (422), saldo tidak berubah (A-07).
 * - Refund (hapus cuti final jenis 1, D6): kebalikan tepat — ke N → N-1 → N-2 dengan batas per kolom
 *   `max(batas D8, snapshot saldo kolom itu di baris cuti)` (v1.3, Q-05: saldo impor di atas D8 tidak dihanguskan);
 *   tanpa snapshot → batas D8 12/6/6; sisa di atas batas dibuang; status 1 `utang = max(0, utang − lcTahunDepan)`;
 *   status 4 `ditangguhkan = max(0, … − lcTahunIni)`. Tidak pernah negatif (A-08).
 * - Jaminan impor (T13): untuk saldo dalam batas D8 hasil potong = normalisasi legacy e-sign `L:3867-3886` (diuji).
 */
final class KuotaCuti
{
    public const KODE_SALDO_TIDAK_TERDAFTAR = 'saldo_tidak_terdaftar';

    public const KODE_KUOTA_TIDAK_CUKUP = 'kuota_tidak_cukup';

    public const KODE_UTANG_MELEBIHI_BATAS = 'utang_melebihi_batas';

    /** [K] `L_cuti.php:1655-1658`. */
    public const PESAN_SALDO_TIDAK_TERDAFTAR = 'Saldo cuti anda tidak terdaftar didalam sistem. Silahkan hubungi admin SIMPEG untuk menambahkan saldo cuti tahunan anda.';

    private function __construct()
    {
    }

    /**
     * Validasi saat ajukan/ubah dan diulang saat keputusan final (§6.3.4 [K `L:1584-1653`]). `detail` berisi angka
     * yang dipakai kalkulator `POST cuti/hitung-lama` (§6.3.7).
     */
    public static function periksa(
        ?SaldoCuti $saldo,
        int $lcTahunIni,
        int $lcTahunDepan,
        int $menungguTahunIni,
        int $menungguTahunDepan,
        int $tahunAcuan,
        AturanCuti $aturan,
    ): HasilKalkulasi {
        if ($saldo === null) {
            return HasilKalkulasi::gagal(self::KODE_SALDO_TIDAK_TERDAFTAR, self::PESAN_SALDO_TIDAK_TERDAFTAR, ['tahun' => $tahunAcuan]);
        }

        $saldoTahunIni   = $saldo->total();
        $saldoTahunDepan = $saldo->saldoTahunDepan($aturan);
        $detail          = [
            'tahun'                => $tahunAcuan,
            'sisa_cuti'            => $saldo->n,
            'sisa_cuti_n1'         => $saldo->n1,
            'sisa_cuti_n2'         => $saldo->n2,
            'saldo_tahun_ini'      => $saldoTahunIni,
            'menunggu_tahun_ini'   => $menungguTahunIni,
            'diajukan_tahun_ini'   => $lcTahunIni,
            'saldo_tahun_depan'    => $saldoTahunDepan,
            'menunggu_tahun_depan' => $menungguTahunDepan,
            'diajukan_tahun_depan' => $lcTahunDepan,
        ];

        if ($lcTahunIni + $menungguTahunIni > $saldoTahunIni || $lcTahunDepan + $menungguTahunDepan > $saldoTahunDepan) {
            return HasilKalkulasi::gagal(self::KODE_KUOTA_TIDAK_CUKUP, self::pesanTidakCukup($detail), $detail);
        }

        return HasilKalkulasi::berhasil(null, $detail);
    }

    /**
     * Potong saldo pada transisi ke 1 (Disetujui) atau 4 (Ditangguhkan). Berhasil → `detail['saldo']` = `SaldoCuti`
     * baru; gagal → saldo tidak berubah (pemanggil membatalkan transaksi).
     */
    public static function potong(SaldoCuti $saldo, int $lcTahunIni, int $lcTahunDepan, int $statusFinal, AturanCuti $aturan): HasilKalkulasi
    {
        self::wajibStatusEfekFinal($statusFinal);
        self::wajibNonNegatif($lcTahunIni, $lcTahunDepan);

        $sisa = $lcTahunIni;
        $n2   = self::kurangi($saldo->n2, $sisa);
        $n1   = self::kurangi($saldo->n1, $sisa);
        $n    = self::kurangi($saldo->n, $sisa);

        if ($sisa > 0) {
            return HasilKalkulasi::gagal(
                self::KODE_KUOTA_TIDAK_CUKUP,
                self::pesanSaldoKurang($saldo->total()),
                ['kurang' => $sisa, 'saldo_tahun_ini' => $saldo->total(), 'diajukan_tahun_ini' => $lcTahunIni],
            );
        }

        $utang        = $saldo->utang;
        $ditangguhkan = $saldo->ditangguhkan;

        if ($statusFinal === StatusCuti::DISETUJUI) {
            $utang += $lcTahunDepan;

            if ($utang > $aturan->maksUtang) {
                return HasilKalkulasi::gagal(
                    self::KODE_UTANG_MELEBIHI_BATAS,
                    self::pesanUtangKurang($saldo->saldoTahunDepan($aturan)),
                    ['saldo_tahun_depan' => $saldo->saldoTahunDepan($aturan), 'diajukan_tahun_depan' => $lcTahunDepan],
                );
            }
        } else {
            $ditangguhkan += $lcTahunIni;
        }

        return HasilKalkulasi::berhasil(null, ['saldo' => new SaldoCuti($n, $n1, $n2, $utang, $ditangguhkan)]);
    }

    /**
     * Refund saat hapus cuti final jenis 1 (D6). Tahun acuan = tahun `created_at` baris yang dihapus (pemanggil).
     * `$snapshot` = saldo pada baris cuti yang dihapus: batas per kolom = `max(batas D8, snapshot kolom itu)`; null atau
     * kolom NULL → batas D8 (perilaku sebelum v1.3).
     */
    public static function kembalikan(
        SaldoCuti $saldo,
        int $lcTahunIni,
        int $lcTahunDepan,
        int $status,
        AturanCuti $aturan,
        ?SnapshotSaldoCuti $snapshot = null,
    ): SaldoCuti {
        self::wajibStatusEfekFinal($status);
        self::wajibNonNegatif($lcTahunIni, $lcTahunDepan);

        $batas = ($snapshot ?? new SnapshotSaldoCuti())->batas($aturan);
        $sisa  = $lcTahunIni;
        $n     = self::tambah($saldo->n, $batas['n'], $sisa);
        $n1    = self::tambah($saldo->n1, $batas['n1'], $sisa);
        $n2    = self::tambah($saldo->n2, $batas['n2'], $sisa);
        // $sisa yang tersisa = kelebihan di atas batas kolom → dibuang.

        return $status === StatusCuti::DISETUJUI
            ? new SaldoCuti($n, $n1, $n2, max(0, $saldo->utang - $lcTahunDepan), $saldo->ditangguhkan)
            : new SaldoCuti($n, $n1, $n2, $saldo->utang, max(0, $saldo->ditangguhkan - $lcTahunIni));
    }

    /**
     * Pesan 422 `errors.lama_cuti` saat ajukan [K `L:1648`].
     *
     * @param array<string, int> $d
     */
    public static function pesanTidakCukup(array $d): string
    {
        $tahun      = $d['tahun'];
        $tahunDepan = $tahun + 1;

        return "Sisa Cuti anda tidak mencukupi. Sisa cuti {$tahun}: {$d['saldo_tahun_ini']} Hari. "
            . "Cuti sedang dalam proses {$tahun}: {$d['menunggu_tahun_ini']} Hari. "
            . "Cuti yang diajukan {$tahun}: {$d['diajukan_tahun_ini']} Hari. "
            . "Sisa utang cuti {$tahunDepan}: {$d['saldo_tahun_depan']}. "
            . "Utang cuti dalam proses {$tahunDepan}: {$d['menunggu_tahun_depan']}. "
            . "Utang cuti yang diajukan {$tahunDepan}: {$d['diajukan_tahun_depan']}.";
    }

    /** Pesan 422 saat keputusan final (§6.4.3, `vp_process_esign`); tahun disisipkan service. */
    public static function pesanSaldoKurang(int $saldoTahunIni): string
    {
        return "Sisa cuti pegawai: {$saldoTahunIni} hari, tidak mencukupi untuk cuti yang diajukan.";
    }

    public static function pesanUtangKurang(int $saldoTahunDepan): string
    {
        return "Sisa utang cuti pegawai: {$saldoTahunDepan} hari, tidak mencukupi untuk porsi cuti tahun berikutnya.";
    }

    /** Kurangi `$saldo` sebanyak mungkin dari `$sisa` (saldo negatif dianggap 0); mengembalikan saldo baru. */
    private static function kurangi(int $saldo, int &$sisa): int
    {
        $ambil = max(0, min($saldo, $sisa));
        $sisa -= $ambil;

        return $saldo - $ambil;
    }

    /** Tambah `$saldo` dari `$sisa` sampai `$batas`; mengembalikan saldo baru. */
    private static function tambah(int $saldo, int $batas, int &$sisa): int
    {
        $tambah = max(0, min($sisa, $batas - $saldo));
        $sisa -= $tambah;

        return $saldo + $tambah;
    }

    private static function wajibStatusEfekFinal(int $status): void
    {
        if (! in_array($status, StatusCuti::EFEK_FINAL, true)) {
            throw new InvalidArgumentException("Potong/refund saldo hanya untuk status 1 atau 4, diberikan {$status}");
        }
    }

    private static function wajibNonNegatif(int $lcTahunIni, int $lcTahunDepan): void
    {
        if ($lcTahunIni < 0 || $lcTahunDepan < 0) {
            throw new InvalidArgumentException("Porsi lama cuti tidak boleh negatif: {$lcTahunIni}/{$lcTahunDepan}");
        }
    }
}
