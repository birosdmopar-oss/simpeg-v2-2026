<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Nip;

/**
 * CR-036 (B-06) — asal sebuah kolom yang merujuk NIP pegawai di registry {@see TabelAnakNip}.
 *
 * Legacy mengubah NIP dengan satu `UPDATE pegawai SET nip` (`libraries/hr/L_employee.php:586-587`); FK legacy
 * `ON UPDATE CASCADE` membawa perubahan ke tabel anak, lalu kode mengganti `pengguna.username` (:589-599). Kolom NIP tanpa FK
 * lainnya tidak disentuh. v2 memakai FK `ON UPDATE RESTRICT`, sehingga aplikasi sendiri yang mengarahkan ulang setiap
 * kolom dalam satu transaksi (keputusan K1).
 */
enum JenisRujukanNip: string
{
    /**
     * FK legacy `REFERENCES pegawai (nip) ON UPDATE CASCADE` di dump struktur produksi.
     */
    case FkLegacy = 'fk_legacy';

    /**
     * Kolom tanpa FK yang ikut diganti kode Ubah NIP legacy (`L_employee.php:589-599`).
     */
    case NonFkUbahNipLegacy = 'non_fk_ubah_nip_legacy';

    /**
     * `gaji_pegawai.nip` (Slip Gaji): tanpa FK dan tidak diganti Ubah NIP legacy.
     */
    case NonFkSlipGaji = 'non_fk_slip_gaji';

    /**
     * Tanpa FK di legacy, tetapi wajib FK ke `pegawai.nip` di v2 menurut spesifikasi (DoD C-01).
     */
    case NonFkFkV2 = 'non_fk_fk_v2';

    /**
     * Apakah Ubah NIP legacy ikut mengganti nilai kolom ini (lewat cascade FK atau kode). `false` berarti setelah Ubah NIP
     * legacy, baris lama menunjuk NIP yang sudah tidak ada.
     */
    public function diubahLegacy(): bool
    {
        return match ($this) {
            self::FkLegacy, self::NonFkUbahNipLegacy => true,
            self::NonFkSlipGaji, self::NonFkFkV2     => false,
        };
    }
}
