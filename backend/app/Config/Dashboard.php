<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Beranda / dashboard per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Milik Jalur D.
 *
 * Override `.env` (opsional, pola BaseConfig): `dashboard.tampilanKomposisi`, `dashboard.nipDisembunyikan`.
 */
class Dashboard extends BaseConfig
{
    public const KOMPOSISI_DONUT = 'donut';

    public const KOMPOSISI_ANGKA = 'angka';

    /**
     * Tampilan komposisi pegawai di beranda admin (Q7-36, deviasi [V2] terlacak — PRD v2.0 Lampiran A):
     * `'donut'` (bawaan) = donut komposisi + tile angka; `'angka'` = persis legacy (angka + persen, `views/user/dashboard_ad.php:47-180`).
     * Data/API sama untuk kedua mode; nilainya dikirim di `GET dashboard/ringkasan` (`tampilan_komposisi`) supaya FE mengikuti tanpa build ulang.
     * Nilai selain dua itu → diperlakukan `'donut'`.
     */
    public string $tampilanKomposisi = self::KOMPOSISI_DONUT;

    /**
     * Daftar NIP yang dikecualikan dari hitungan dashboard (Q7-41, ikut legacy `$whereHide_nip` di `libraries/hr/L_user.php`), dipisah koma.
     * **RAHASIA server: isi HANYA lewat `.env` server `dashboard.nipDisembunyikan`, tidak pernah di repo/test** (test memakai NIP sintetis).
     * Kosong = tanpa pengecualian. Hitungan mana yang memakai pengecualian mengikuti legacy per lingkup — lihat `RingkasanBeranda`.
     */
    public string $nipDisembunyikan = '';

    /**
     * `id_jabatan` "Direktur Politeknik" untuk tabel masa kerja direktur (role 1/4; Q7-38 — legacy hardcode `$arrIdDir` di
     * `L_user::dashboard_sa`). Diubah di sini bila struktur jabatan berubah, tanpa DBV.
     *
     * @var list<int>
     */
    public array $idJabatanDirektur = [1914, 617, 616, 150, 149, 41];

    /**
     * Masa cache ringkasan beranda per lingkup (detik). Angka "hari ini" (TB, IB, CLTN, cuti, presensi online) tidak di-cache.
     */
    public int $ttlRingkasanDetik = 900;

    public function modeKomposisi(): string
    {
        return $this->tampilanKomposisi === self::KOMPOSISI_ANGKA ? self::KOMPOSISI_ANGKA : self::KOMPOSISI_DONUT;
    }

    /**
     * @return list<string> NIP unik tanpa spasi; kosong bila tidak dikonfigurasi
     */
    public function daftarNipDisembunyikan(): array
    {
        $nip = array_filter(array_map('trim', explode(',', $this->nipDisembunyikan)), static fn (string $v): bool => $v !== '');

        return array_values(array_unique($nip));
    }
}
