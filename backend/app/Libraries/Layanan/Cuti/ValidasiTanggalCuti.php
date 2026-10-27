<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * MAKE-022 Tahap 2b — validasi tanggal pengajuan cuti (PRD Fase 4 v1.1 §6.2.3 baris `tgl_mulai`/`tgl_akhir`/`durasi`,
 * §6.3.2 batas jenis 5/6), murni: "hari ini" dan tahun acuan diterima dari pemanggil. Semua galat dikumpulkan
 * (A-09) sebagai `errors.<field>` → pesan.
 *
 * Aturan [K] `L_cuti.php:1540-1554, 1567-1575, 1666-1674` dan [V2] keputusan #6 (hanya tahun acuan + tahun berikutnya):
 * - `tgl_mulai` wajib, `YYYY-MM-DD`; jenis 1/2/4/6 tidak boleh sebelum hari ini (jenis 3/5 boleh mundur); tidak boleh
 *   sebelum 01-01 tahun acuan.
 * - `tgl_akhir` wajib kecuali jenis 2 (dihitung dari `durasi` ∈ {1,2,3}); ≥ `tgl_mulai`; **jenis 1** ≤ 31-12 tahun
 *   acuan + 1 (batas dua tahun kuota, keputusan #6 — tidak diterapkan ke jenis lain karena CLTN sah sampai 3 tahun dan
 *   jenis 2–6 tidak memotong kuota; dicatat untuk reviewer); jenis 5 ≤ `tgl_mulai` + 1 bulan PHP (31-01-2025 →
 *   03-03-2025, inklusif); jenis 6 ≤ `tgl_mulai` + 3 tahun PHP (29-02-2024 → 01-03-2027, inklusif).
 * Validasi lain (jenis boleh dipilih, tumpang tindih, kuota, lama 0) ada di pemanggil / `KuotaCuti`.
 */
final class ValidasiTanggalCuti
{
    public const PESAN_MULAI_WAJIB = 'Tanggal mulai cuti wajib diisi.';

    public const PESAN_MULAI_TIDAK_VALID = 'Tanggal mulai cuti tidak valid.';

    public const PESAN_MULAI_SEBELUM_HARI_INI = 'Tanggal mulai cuti tidak boleh sebelum hari ini.';

    public const PESAN_MULAI_TAHUN_SEBELUMNYA = 'Tanggal cuti tidak boleh pada tahun sebelum tahun pengajuan.';

    public const PESAN_AKHIR_WAJIB = 'Tanggal akhir cuti wajib diisi.';

    public const PESAN_AKHIR_TIDAK_VALID = 'Tanggal akhir cuti tidak valid.';

    public const PESAN_AKHIR_SEBELUM_MULAI = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';

    public const PESAN_DURASI = 'Silahkan pilih durasi cuti besar.';

    public const PESAN_LAMA_NOL = 'Lama cuti tidak boleh 0 hari.';

    private function __construct()
    {
    }

    /**
     * @return array{errors: array<string, string>, tgl_akhir: string|null} `tgl_akhir` = tanggal akhir efektif (jenis
     *                                                                      2: hasil hitung dari `durasi`), `null` bila ada galat pada tanggal
     */
    public static function periksa(
        int $jenis,
        ?string $tglMulai,
        ?string $tglAkhir,
        ?int $durasi,
        string $hariIni,
        int $tahunAcuan,
        AturanCuti $aturan,
    ): array {
        $errors = [];
        $mulai  = self::tanggalInput($tglMulai, $errors, 'tgl_mulai', self::PESAN_MULAI_WAJIB, self::PESAN_MULAI_TIDAK_VALID);

        if ($mulai !== null) {
            if (! in_array($jenis, [AturanCuti::JENIS_SAKIT, AturanCuti::JENIS_ALASAN_PENTING], true) && $mulai < $hariIni) {
                $errors['tgl_mulai'] = self::PESAN_MULAI_SEBELUM_HARI_INI;
            } elseif ($mulai < sprintf('%04d-01-01', $tahunAcuan)) {
                $errors['tgl_mulai'] = self::PESAN_MULAI_TAHUN_SEBELUMNYA;
            }
        }

        if ($jenis === AturanCuti::JENIS_BESAR) {
            if ($durasi === null || ! in_array($durasi, $aturan->durasiBesar, true)) {
                $errors['durasi'] = self::PESAN_DURASI;
                $akhir            = null;
            } else {
                $akhir = $mulai === null ? null : HitungLamaCuti::tglAkhirBesar($mulai, $durasi, $aturan);
            }
        } else {
            $akhir = self::tanggalInput($tglAkhir, $errors, 'tgl_akhir', self::PESAN_AKHIR_WAJIB, self::PESAN_AKHIR_TIDAK_VALID);
        }

        if ($mulai !== null && $akhir !== null && ! isset($errors['tgl_akhir'])) {
            $batasTahun = sprintf('%04d-12-31', $tahunAcuan + 1);

            if ($akhir < $mulai) {
                $errors['tgl_akhir'] = self::PESAN_AKHIR_SEBELUM_MULAI;
            } elseif ($jenis === AturanCuti::JENIS_TAHUNAN && $akhir > $batasTahun) {
                $errors['tgl_akhir'] = self::pesanAkhirMaksimal($tahunAcuan + 1);
            } elseif ($jenis === AturanCuti::JENIS_ALASAN_PENTING && $akhir > self::batasAlasanPenting($mulai, $aturan)) {
                $errors['tgl_akhir'] = self::pesanBatasAlasanPenting($aturan);
            } elseif ($jenis === AturanCuti::JENIS_CLTN && $akhir > self::batasCltn($mulai, $aturan)) {
                $errors['tgl_akhir'] = self::pesanBatasCltn($aturan);
            }
        }

        $adaGalatTanggal = isset($errors['tgl_mulai']) || isset($errors['tgl_akhir']) || isset($errors['durasi']);

        return ['errors' => $errors, 'tgl_akhir' => $adaGalatTanggal ? null : $akhir];
    }

    /**
     * Batas `tgl_akhir` cuti alasan penting = `tgl_mulai` + n bulan PHP (`DateTime::modify('+1 month')`, meluap ke bulan
     * berikutnya: 31-01-2025 → 03-03-2025; 31-01-2024 → 02-03-2024) [K `L:1666-1674`; keputusan #4]. Inklusif.
     */
    public static function batasAlasanPenting(string $mulai, AturanCuti $aturan): string
    {
        return HitungLamaCuti::tanggal($mulai)->modify("+{$aturan->maksBulanAlasanPenting} month")->format('Y-m-d');
    }

    /**
     * Batas `tgl_akhir` CLTN = `tgl_mulai` + n tahun PHP (29-02-2024 → 01-03-2027) [K `L:1567-1575`]. Inklusif.
     */
    public static function batasCltn(string $mulai, AturanCuti $aturan): string
    {
        return HitungLamaCuti::tanggal($mulai)->modify("+{$aturan->maksTahunCltn} year")->format('Y-m-d');
    }

    /**
     * Lama hasil server 0 (rentang seluruhnya libur/akhir pekan) → `errors.tgl_mulai` [K `L:1519`]; `null` = sah.
     */
    public static function periksaLama(int $lama): ?string
    {
        return $lama <= 0 ? self::PESAN_LAMA_NOL : null;
    }

    public static function pesanAkhirMaksimal(int $tahun): string
    {
        return "Tanggal akhir cuti maksimal 31-12-{$tahun}.";
    }

    public static function pesanBatasAlasanPenting(AturanCuti $aturan): string
    {
        return "Lama cuti karena alasan penting maksimal {$aturan->maksBulanAlasanPenting} bulan.";
    }

    public static function pesanBatasCltn(AturanCuti $aturan): string
    {
        return "Lama cuti di luar tanggungan negara maksimal {$aturan->maksTahunCltn} tahun.";
    }

    /**
     * @param array<string, string> $errors
     */
    private static function tanggalInput(?string $nilai, array &$errors, string $field, string $pesanWajib, string $pesanTidakValid): ?string
    {
        $nilai = $nilai === null ? '' : trim($nilai);

        if ($nilai === '') {
            $errors[$field] = $pesanWajib;

            return null;
        }

        if (! TanggalBisnis::valid($nilai)) {
            $errors[$field] = $pesanTidakValid;

            return null;
        }

        return $nilai;
    }
}
