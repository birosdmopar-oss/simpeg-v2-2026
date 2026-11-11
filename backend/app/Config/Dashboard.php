<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Beranda / dashboard per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Milik Jalur D.
 *
 * Override `.env` (opsional, pola BaseConfig): `dashboard.tampilanKomposisi`, `dashboard.nipDisembunyikan`,
 * `dashboard.penggunaLihatSemua`, `dashboard.nipDikecualikanUlangTahun`, `dashboard.tandaJasaIkutLegacy`.
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
     * Akun yang TIDAK memakai pengecualian {@see self::$nipDisembunyikan} (Q-D12 A, ikut legacy: `$whereHide_nip` kosong
     * untuk daftar `id` pengguna hardcode di `L_user::dashboard_sa|satker|unit`): `id_pengguna` dipisah koma.
     * **RAHASIA server: isi HANYA lewat `.env` server `dashboard.penggunaLihatSemua`.** Kosong = semua akun memakai pengecualian.
     */
    public string $penggunaLihatSemua = '';

    /**
     * NIP yang tidak ditampilkan di kartu ulang tahun (Q-D18 A, ikut pengecualian legacy `getTodayBday`/`getTomorrowBday`),
     * dipisah koma. **RAHASIA server: isi HANYA lewat `.env` server `dashboard.nipDikecualikanUlangTahun`** (operator mengisi
     * dari legacy). Kosong = tanpa pengecualian.
     */
    public string $nipDikecualikanUlangTahun = '';

    /**
     * `id_jabatan` "Direktur Politeknik" untuk tabel masa kerja direktur (role 1/4; Q7-38 — legacy hardcode `$arrIdDir` di
     * `L_user::dashboard_sa`). Diubah di sini bila struktur jabatan berubah, tanpa DBV.
     *
     * @var list<int>
     */
    public array $idJabatanDirektur = [1914, 617, 616, 150, 149, 41];

    /**
     * Kartu Tanda Jasa beranda role 3 (satker/unit) — deviasi [V2] keputusan Horii 10-11-2026 (README `Libraries/Dashboard`,
     * "Deviasi dari legacy — Tanda Jasa satker/unit").
     * `false` (bawaan, [V2]): tahun acuan sama dengan kementerian (tahun berjalan bila bulan < 9, tahun depan bila ≥ 9) untuk
     * proyeksi dan tanggal pembanding, sehingga kartu tidak ≈0 pada September–Desember.
     * `true`: persis legacy `L_user::dashboard_satker|unit` (proyeksi selalu tahun berjalan; pembanding 1 Agustus tahun depan
     * mulai September → praktis 0). Kementerian tidak terpengaruh saklar ini.
     */
    public bool $tandaJasaIkutLegacy = false;

    /**
     * Ulang tahun beranda (Q7-45, ikut legacy `helpers/function_helper.php:1419-1475` getTodayBday/getTomorrowBday): satker yang
     * dikecualikan (`pmj.id_satker != 171`) — Q-D20 (A), bukan rahasia.
     *
     * @var list<int>
     */
    public array $idSatkerDikecualikanUlangTahun = [171];

    /**
     * Label jabatan untuk baris khusus `nip = '1'` di kartu ulang tahun (view `dashboard_ad.php:270-276`): hanya nama, tanggal
     * lahir, dan label ini (tanpa NIP/satker) — Q-D20 (A).
     */
    public string $labelJabatanNipSatu = 'Menteri Pariwisata';

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
        return self::daftar($this->nipDisembunyikan);
    }

    /**
     * Pengecualian NIP yang berlaku untuk akun ini (Q-D12): kosong bila `$idPengguna` ada di {@see self::$penggunaLihatSemua}.
     *
     * @return list<string>
     */
    public function nipDisembunyikanUntuk(?int $idPengguna): array
    {
        if ($idPengguna !== null && in_array((string) $idPengguna, self::daftar($this->penggunaLihatSemua), true)) {
            return [];
        }

        return $this->daftarNipDisembunyikan();
    }

    /**
     * @return list<string> NIP unik tanpa spasi; kosong bila tidak dikonfigurasi (Q-D18)
     */
    public function daftarNipDikecualikanUlangTahun(): array
    {
        return self::daftar($this->nipDikecualikanUlangTahun);
    }

    /**
     * @return list<string>
     */
    private static function daftar(string $nilai): array
    {
        $isi = array_filter(array_map('trim', explode(',', $nilai)), static fn (string $v): bool => $v !== '');

        return array_values(array_unique($isi));
    }
}
