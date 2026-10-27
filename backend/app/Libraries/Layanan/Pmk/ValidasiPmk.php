<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Layanan\Studi\RentangTanggal;

/**
 * MAKE-024 (bagian murni) — validasi isian pengajuan PMK (`POST/PUT pmk/pengajuan`, PRD Fase 4 v1.1 §6.9.1), murni: TMT
 * CPNS pegawai dan baris pembanding tumpang tindih disediakan pemanggil; berkas/hak/role di service. Semua galat
 * dikumpulkan (`errors.<field>` → pesan; legacy `vp_req` sudah memakai `.=` — teks [K] `L_pmk.php:753-906`).
 *
 * - Wajib: `jenis_pmk` ∈ {Swasta, Negeri} [V2 P-5 enum], `instansi`, `tgl_awal`, `tgl_akhir`, `mker_th`, `mker_bl`
 *   (dari klien [K D19/P-4]; bilangan bulat 0..255 = TINYINT [V2], legacy hanya cek kosong).
 * - `tgl_akhir >= tgl_awal` [K `:806-810`] — dicek bila kedua tanggal sah (legacy: bila tidak ada galat lain; di v2 semua
 *   galat dikumpulkan, jadi syaratnya cukup kedua tanggal sah — keputusan coder #4).
 * - TMT CPNS: periode PMK harus berakhir sebelum TMT CPNS → galat bila `tgl_akhir >= TMT CPNS` [V2 P-3, Q-15; legacy hanya
 *   `TMT CPNS < tgl_awal` `:813-826`]. Pesan [K]. TMT CPNS tidak diketahui (`null`) → tidak dicek (keputusan coder #5).
 * - Tumpang tindih dengan pengajuan lain NIP sama berstatus ≠ 2, inklusif [K `:846-855, 880-891`] → `errors.tgl_awal`.
 */
final class ValidasiPmk
{
    public const PESAN_JENIS_WAJIB = 'Silahkan pilih jenis PMK.';

    public const PESAN_JENIS_TIDAK_VALID = 'Jenis PMK hanya boleh Swasta atau Negeri.';

    public const PESAN_INSTANSI_WAJIB = 'Instansi perusahaan wajib diisi.';

    public const PESAN_URUTAN = 'Tanggal awal harus lebih kecil atau sama dengan tanggal akhir.';

    public const PESAN_BENTROK = 'Tanggal pengajuan PMK sudah terdaftar didalam sistem, silahkan gunakan tanggal pengajuan yang berbeda.';

    private const MKER = [
        'mker_th' => 'Masa kerja (th.)',
        'mker_bl' => 'Masa kerja (bl.)',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function periksa(array $data, ?string $tmtCpns, AturanPmk $aturan): array
    {
        $errors = [];
        $jenis  = self::teks($data, 'jenis_pmk');

        if ($jenis === '') {
            $errors['jenis_pmk'] = self::PESAN_JENIS_WAJIB;
        } elseif (! in_array($jenis, $aturan->jenisPmk, true)) {
            $errors['jenis_pmk'] = self::PESAN_JENIS_TIDAK_VALID;
        }

        if (self::teks($data, 'instansi') === '') {
            $errors['instansi'] = self::PESAN_INSTANSI_WAJIB;
        }

        $awal  = self::tanggal($data, 'tgl_awal', 'Tanggal awal', $errors);
        $akhir = self::tanggal($data, 'tgl_akhir', 'Tanggal akhir', $errors);

        if ($awal !== null && $akhir !== null) {
            if ($akhir < $awal) {
                $errors['tgl_akhir'] = self::PESAN_URUTAN;
            } elseif ($tmtCpns !== null && TanggalBisnis::valid($tmtCpns) && $akhir >= $tmtCpns) {
                $errors['tgl_akhir'] = self::pesanTmtCpns($tmtCpns);
            }
        }

        foreach (self::MKER as $field => $label) {
            $nilai = self::teks($data, $field);

            if ($nilai === '') {
                $errors[$field] = "{$label} wajib diisi.";
            } elseif (! ctype_digit($nilai) || (int) $nilai > $aturan->maksMker) {
                $errors[$field] = "{$label} harus bilangan bulat 0 sampai {$aturan->maksMker}.";
            }
        }

        return $errors;
    }

    /**
     * [K `L_pmk.php:824`] — tanggal dalam format Indonesia.
     */
    public static function pesanTmtCpns(string $tmtCpns): string
    {
        return 'Tanggal peninjauan PMK tidak boleh beririsan dengan TMT CPNS (' . TanggalBisnis::formatIndonesia($tmtCpns) . ').';
    }

    /**
     * Pengajuan lain yang beririsan (status ≠ 2); `null` bila tidak ada. Baris: `id`, `tgl_awal`, `tgl_akhir`, `status`.
     *
     * @param list<array{id?: int|string|null, tgl_awal: string, tgl_akhir: string, status: int|string}> $lain
     *
     * @return array<string, mixed>|null
     */
    public static function bentrok(string $awal, string $akhir, array $lain, int|string|null $abaikanId = null): ?array
    {
        foreach ($lain as $baris) {
            if ($abaikanId !== null && isset($baris['id']) && (string) $baris['id'] === (string) $abaikanId) {
                continue;
            }

            if (StatusPmk::ikutTumpangTindih((int) $baris['status'])
                && RentangTanggal::beririsan($awal, $akhir, (string) $baris['tgl_awal'], (string) $baris['tgl_akhir'])) {
                return $baris;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private static function tanggal(array $data, string $field, string $label, array &$errors): ?string
    {
        $nilai = self::teks($data, $field);

        if ($nilai === '') {
            $errors[$field] = "{$label} wajib diisi.";

            return null;
        }

        if (! TanggalBisnis::valid($nilai)) {
            $errors[$field] = "{$label} tidak valid.";

            return null;
        }

        return $nilai;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function teks(array $data, string $field): string
    {
        $nilai = $data[$field] ?? null;

        return is_scalar($nilai) ? trim((string) $nilai) : '';
    }
}
