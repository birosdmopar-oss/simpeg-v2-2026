<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Nip;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;

/**
 * CR-036 — format NIP untuk input pengguna Kepegawaian (B-03/B-05 tambah pegawai, B-06 koreksi NIP).
 *
 * Aturan sama dengan akun (DBV-010): angka ASCII saja, 1–18 digit. NIP PNS 18 digit dan NIK 16 digit pegawai Non-PNS
 * sama-sama diterima, begitu pula nomor pendek pegawai lama; kolom `nip` VARCHAR(30) (K1). Sumber yang sama di Auth:
 * `PenggunaModel::NIP_MAX_DIGITS`, `UserService::validateNip()`, `AccountProvisioner::provisionForPegawai()`.
 * Keselarasannya dijaga `FormatNipTest`, karena kelas murni ini tidak boleh memuat Model.
 *
 * Legacy tidak memvalidasi format di server. Form Ubah NIP hanya memakai inputmask 18 digit
 * (`views/hr/employee/form_nip.php:62`), dan `update_nip` hanya menolak isian kosong (`libraries/hr/L_employee.php:561-568`).
 *
 * Hasil gagal memakai {@see HasilKalkulasi}. Service memanggil `->lemparBilaGagal('nip')`, sehingga input salah menjadi 422.
 * Keunikan NIP baru, baik di `pegawai` maupun sebagai `username` akun lain, dicek service ber-DB.
 */
final class FormatNip
{
    /**
     * Jumlah digit maksimum. Nilainya harus sama dengan `PenggunaModel::NIP_MAX_DIGITS`.
     */
    public const MAKS_DIGIT = 18;

    private function __construct()
    {
    }

    /**
     * Apakah string tepat 1–18 digit ASCII, tanpa spasi atau baris baru di ujungnya.
     */
    public static function valid(string $nip): bool
    {
        return preg_match('/^[0-9]{1,' . self::MAKS_DIGIT . '}\z/', $nip) === 1;
    }

    /**
     * Memeriksa NIP dari input pengguna. Spasi di ujung dibuang dulu, sama dengan Auth. Hasil valid membawa
     * `detail.nip` (sudah dinormalisasi). Hasil gagal berkode `nip_wajib` atau `nip_tidak_valid`.
     *
     * Nilai yang bukan string, misalnya angka JSON (nol di depan hilang) atau array, ditolak sebagai `nip_tidak_valid`.
     */
    public static function periksa(mixed $nip, string $label = 'NIP'): HasilKalkulasi
    {
        if ($nip === null || (is_string($nip) && trim($nip) === '')) {
            return HasilKalkulasi::gagal('nip_wajib', "{$label} wajib diisi.");
        }

        $normal = is_string($nip) ? trim($nip) : null;

        if ($normal === null || ! self::valid($normal)) {
            return HasilKalkulasi::gagal('nip_tidak_valid', "{$label} harus berupa angka, maksimal " . self::MAKS_DIGIT . ' digit.');
        }

        return HasilKalkulasi::berhasil(null, ['nip' => $normal]);
    }

    /**
     * Pemeriksaan murni untuk koreksi NIP (B-06), mengikuti legacy `update_nip` (`L_employee.php:561-576`):
     * - NIP lama kosong → `nip_lama_wajib`.
     * - NIP baru kosong atau formatnya salah → `nip_wajib` / `nip_tidak_valid`, dengan label "NIP baru".
     * - NIP baru sama dengan NIP lama → `nip_baru_sama`. Legacy menolak kasus ini lewat cek keunikan, karena NIP lama
     *   ditemukan sebagai pegawai yang sudah terdaftar.
     *
     * Format NIP lama tidak divalidasi karena itu data yang sudah tersimpan. Hasil valid membawa `detail.nip_lama` dan
     * `detail.nip_baru`, keduanya sudah di-trim.
     */
    public static function periksaKoreksi(mixed $nipLama, mixed $nipBaru): HasilKalkulasi
    {
        if (! is_string($nipLama) || trim($nipLama) === '') {
            return HasilKalkulasi::gagal('nip_lama_wajib', 'Pilih pegawai yang NIP-nya akan dikoreksi.');
        }

        $baru = self::periksa($nipBaru, 'NIP baru');

        if (! $baru->valid) {
            return $baru;
        }

        $lama = trim($nipLama);

        if ($baru->detail['nip'] === $lama) {
            return HasilKalkulasi::gagal('nip_baru_sama', 'NIP baru sama dengan NIP lama.');
        }

        return HasilKalkulasi::berhasil(null, ['nip_lama' => $lama, 'nip_baru' => $baru->detail['nip']]);
    }
}
