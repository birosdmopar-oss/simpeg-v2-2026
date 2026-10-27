<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * MAKE-024 (bagian murni) — validasi isian pengajuan TB (`POST/PUT tb`, PRD Fase 4 v1.1 §6.7.2) dan IB (§6.8.1: sama TB
 * kecuali disebut), murni: master jenjang, berkas, hak/role, dan baris pembanding tumpang tindih disediakan pemanggil.
 * Semua galat dikumpulkan sebagai `errors.<field>` → pesan [V2 TB-03/A-09; legacy menimpa pesan `L_tb.php:832-886`].
 *
 * Aturan [K `L_tb.php:821-987`, `L_ib.php:641-772`]:
 * - Wajib: `tgl_mulai`, `tgl_akhir`, `id_jenjang_pendidikan` (> 3 [K PRD]; status master dicek pemanggil),
 *   `institusi_pendidikan`, `jurusan_pendidikan`, `lokasi_pendidikan`, `lembaga_yang_mendanai`, `up`.
 *   `lama_tahun` tidak divalidasi — dihitung server ({@see LamaTahunStudi}, Q-03).
 * - `tgl_akhir >= tgl_mulai` (sama hari boleh) → `errors.tgl_akhir` "Tanggal akhir tidak boleh sebelum tanggal mulai."
 *   (teks PRD §6.7.2).
 * - TB input role 1 dengan `status = 1`: `no_sk`, `tgl_sk`, `ds_tb`, `no_ds_tb` wajib (`L_tb.php:870-887`). IB tidak
 *   punya status awal selain 0 (§6.8.1) — `status` bukan 0 ditolak.
 * - Tumpang tindih: NIP sama, rentang beririsan inklusif, status ∈ {@see StatusStudi::TUMPANG_TINDIH} → `errors.tgl_mulai`
 *   (`L_tb.php:928-937`). Hanya dengan layanan sejenis: TB vs IB boleh beririsan [K IB-09, Q-11].
 */
final class ValidasiStudi
{
    /** Isian teks wajib → label pesan (`{nama}` = "tugas belajar"/"izin belajar"); teks [K] `L_tb.php:848-864`. */
    private const WAJIB_TEKS = [
        'institusi_pendidikan'  => 'Institusi pendidikan',
        'jurusan_pendidikan'    => 'Jurusan pendidikan',
        'lokasi_pendidikan'     => 'Lokasi pendidikan {nama}',
        'lembaga_yang_mendanai' => 'Lembaga yang mendanai {nama}',
        'up'                    => 'U.P laporan {nama}',
    ];

    /** Data SK wajib saat TB berstatus 1 → label [K `L_tb.php:870-887`]. */
    public const SK_TB = [
        'no_sk'    => 'No. SK Tugas Belajar',
        'tgl_sk'   => 'Tgl. SK Tugas Belajar',
        'ds_tb'    => 'Dasar Surat Tugas Belajar',
        'no_ds_tb' => 'No. Dasar Surat Tugas Belajar',
    ];

    public const PESAN_AKHIR_SEBELUM_MULAI = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';

    public const PESAN_STATUS_TB = 'Status awal tugas belajar hanya boleh 0 (Pengajuan) atau 1 (Disetujui).';

    public const PESAN_STATUS_IB = 'Pengajuan izin belajar selalu berstatus Pengajuan.';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data   isian request
     * @param int|null             $status status awal yang dikirim role 1 (TB 0|1); `null` = tidak dikirim (dipaksa 0)
     *
     * @return array{errors: array<string, string>, lama_tahun: string|null} `lama_tahun` = nilai kolom bila tanggal sah
     */
    public static function periksa(string $jenis, array $data, ?int $status, AturanStudi $aturan): array
    {
        $nama   = AturanStudi::namaLayanan($jenis);
        $errors = [];

        $mulai = self::tanggal($data, 'tgl_mulai', "Tanggal mulai {$nama}", $errors);
        $akhir = self::tanggal($data, 'tgl_akhir', "Tanggal akhir {$nama}", $errors);

        if ($mulai !== null && $akhir !== null && $akhir < $mulai) {
            $errors['tgl_akhir'] = self::PESAN_AKHIR_SEBELUM_MULAI;
        }

        $jenjang = self::teks($data, 'id_jenjang_pendidikan');

        if ($jenjang === '') {
            $errors['id_jenjang_pendidikan'] = "Silahkan pilih jenjang pendidikan {$nama}.";
        } elseif (! ctype_digit($jenjang) || (int) $jenjang <= $aturan->jenjangMinEksklusif) {
            $errors['id_jenjang_pendidikan'] = "Jenjang pendidikan {$nama} tidak valid.";
        }

        foreach (self::WAJIB_TEKS as $field => $label) {
            if (self::teks($data, $field) === '') {
                $errors[$field] = str_replace('{nama}', $nama, $label) . ' wajib diisi.';
            }
        }

        if ($status !== null) {
            if ($jenis === AturanStudi::JENIS_IB) {
                if ($status !== StatusStudi::PENGAJUAN) {
                    $errors['status'] = self::PESAN_STATUS_IB;
                }
            } elseif (! in_array($status, [StatusStudi::PENGAJUAN, StatusStudi::DISETUJUI], true)) {
                $errors['status'] = self::PESAN_STATUS_TB;
            } elseif ($status === StatusStudi::DISETUJUI) {
                $errors += self::periksaSk($data);
            }
        }

        $lama = $mulai !== null && $akhir !== null && ! isset($errors['tgl_akhir'])
            ? LamaTahunStudi::nilaiKolom($mulai, $akhir)
            : null;

        return ['errors' => $errors, 'lama_tahun' => $lama];
    }

    /**
     * Data SK TB (status 1 input admin, juga syarat setujui — {@see TransisiStudi}); `tgl_sk` harus tanggal sah.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $label field → label pesan
     *
     * @return array<string, string>
     */
    public static function periksaSk(array $data, array $label = self::SK_TB): array
    {
        $errors = [];

        foreach ($label as $field => $teks) {
            $nilai = self::teks($data, $field);

            if ($nilai === '') {
                $errors[$field] = "{$teks} wajib diisi.";
            } elseif (str_starts_with($field, 'tgl_') && ! TanggalBisnis::valid($nilai)) {
                $errors[$field] = "{$teks} tidak valid.";
            }
        }

        return $errors;
    }

    /**
     * Pengajuan lain milik NIP yang sama yang beririsan; `null` bila tidak ada. Baris: `id`, `tgl_mulai`, `tgl_akhir`,
     * `status` (baris dengan id = `$abaikanId` dilewati — ubah data sendiri).
     *
     * @param list<array{id?: int|string|null, tgl_mulai: string, tgl_akhir: string, status: int|string}> $lain
     *
     * @return array<string, mixed>|null
     */
    public static function bentrok(string $mulai, string $akhir, array $lain, int|string|null $abaikanId = null): ?array
    {
        foreach ($lain as $baris) {
            if ($abaikanId !== null && isset($baris['id']) && (string) $baris['id'] === (string) $abaikanId) {
                continue;
            }

            if (in_array((int) $baris['status'], StatusStudi::TUMPANG_TINDIH, true)
                && RentangTanggal::beririsan($mulai, $akhir, (string) $baris['tgl_mulai'], (string) $baris['tgl_akhir'])) {
                return $baris;
            }
        }

        return null;
    }

    /**
     * Pesan tumpang tindih [K `L_tb.php:935`, `L_ib.php:726`].
     */
    public static function pesanBentrok(string $jenis): string
    {
        $nama = AturanStudi::namaLayanan($jenis);

        return "Tanggal {$nama} sudah terdaftar didalam sistem, silahkan gunakan tanggal {$nama} yang berbeda.";
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
