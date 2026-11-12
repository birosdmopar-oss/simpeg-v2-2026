<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi halaman Arsip F-10 (PRD F-10 v1.3 §7.2, §7.4, §8.3; MAKE-090).
 *
 * Dua jalur buka berkas (F10-18):
 *  - berkas LEGACY (path `arsip/…`, `assets/upload/…` hasil salinan MAKE-069) dibuka lewat URL publik Nginx
 *    `{dasarUrlBerkas}/<path>` tanpa cek izin, persis legacy (F10-18 (a)). Saklar rollback `penyajianBerkasLegacy`
 *    = 'privat' memindahkannya ke endpoint `buka` (F10-24, Lampiran A);
 *  - berkas UNGGAHAN v2 (kunci `YYYY/MM/<32 hex>.<ext>`) SELALU privat lewat
 *    `GET pegawai/{nip}/arsip/berkas/{id}/buka` (F10-18 (b), F10-34..36), tidak dipengaruhi saklar.
 *
 * Nilai bisa diganti lewat `.env`: `arsip.penyajianBerkasLegacy`, `arsip.dasarUrlBerkas`, `arsip.sajiBerkasDev`.
 * Host tidak pernah ditulis di repo.
 */
class Arsip extends BaseConfig
{
    public const PENYAJIAN_PUBLIK = 'publik';

    public const PENYAJIAN_PRIVAT = 'privat';

    /**
     * Regex kunci berkas unggahan v2 (`AttachmentService::simpan()`), atas path ternormalisasi. HANYA di sini: tidak ada
     * di blok Nginx (berkas unggahan v2 privat, F10-18 (b)).
     */
    public const REGEX_KUNCI_V2 = '^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.[a-z0-9]{1,10}$';

    /**
     * Regex berkas legacy server arsip (`arsip/<NIP>/<sub>/<nama>`). SAMA PERSIS dengan blok Nginx README-deploy §1l
     * (dibandingkan sebagai string oleh test MAKE-093). Segmen berawalan `.` tidak cocok.
     */
    public const REGEX_LEGACY_ARSIP = '^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$';

    /**
     * Regex berkas legacy server aplikasi (`assets/upload/…`, F10-33). SAMA PERSIS dengan blok Nginx README-deploy §1l.
     */
    public const REGEX_LEGACY_ASSETS = '^assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*$';

    /**
     * Peta tertutup ekstensi pratinjau → Content-Type (F10-32, F10-34 (4)). Ekstensi lain = unduh
     * `application/octet-stream`.
     *
     * @var array<string, string>
     */
    public const TIPE_PRATINJAU = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];

    public const TIPE_UNDUH = 'application/octet-stream';

    /**
     * CSP respons berkas; sama dengan snippet Nginx §7.3 (F10-34 (4)).
     */
    public const CSP_BERKAS = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'";

    /**
     * Entity `audit_logs` pembukaan daftar arsip pegawai lain (F10-17, §8.2) dan pembukaan berkas privat (F10-35).
     */
    public const AUDIT_LIHAT = 'arsip_lihat';

    public const AUDIT_UNDUH = 'arsip_unduh';

    /**
     * Dedup catatan `arsip_lihat` folder/daftar per (aktor, NIP), detik (F10-26).
     */
    public const DEDUP_LIHAT_DETIK = 900;

    /**
     * Kapasitas ember throttle `buka` per akun per menit (token bucket CI4, F10-36). Sengaja tidak merujuk
     * `DokumenService::UNDUH_PER_MENIT`.
     */
    public const BUKA_PER_MENIT = 6;

    public const PESAN_THROTTLE = 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.';

    /**
     * Paginasi daftar berkas (F10-20).
     */
    public const PER_PAGE_BAWAAN = 25;

    public const PER_PAGE_MAKS = 100;

    /**
     * 'publik' (bawaan, F10-18 (a)) atau 'privat' (rollback: berkas legacy juga lewat endpoint `buka`, F10-24). Nilai
     * lain diperlakukan 'privat' (fail-closed).
     */
    public string $penyajianBerkasLegacy = self::PENYAJIAN_PUBLIK;

    /**
     * Dasar URL publik berkas legacy (relatif ke host aplikasi, atau absolut untuk subdomain berkas / backend dev §7.4).
     */
    public string $dasarUrlBerkas = '/berkas';

    /**
     * Rute dev `GET berkas/(:any)` (BerkasDevController) — hanya didaftarkan bila ENVIRONMENT === 'development' DAN
     * nilai ini true (§7.4). Tidak berpengaruh di testing/production.
     */
    public bool $sajiBerkasDev = false;

    /**
     * Mode penyajian berkas legacy yang berlaku ('publik' hanya bila disetel persis 'publik').
     */
    public function modeLegacy(): string
    {
        return trim($this->penyajianBerkasLegacy) === self::PENYAJIAN_PUBLIK ? self::PENYAJIAN_PUBLIK : self::PENYAJIAN_PRIVAT;
    }
}
