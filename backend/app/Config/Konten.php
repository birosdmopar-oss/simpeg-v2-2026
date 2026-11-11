<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi konten admin Fase 7 (PRD v2.0 Q7-06, Q7-14). Batas gambar popup/banner: unggah biasa + cek dimensi/rasio di
 * server (tanpa cropper). Angka dimensi ⚑ belum ada acuan tertulis di legacy selain keluaran cropper banner (rasio 4:1,
 * lebar 1600 px, `views/hr/announcement/banner/form.php`); batas di bawah adalah keputusan v2 yang bisa diubah di sini.
 */
class Konten extends BaseConfig
{
    /** Ukuran maksimum gambar (MB) — salah satu dari 1, 2, 5 (AturanLampiran). */
    public int $gambarMaksMb = 5;

    /**
     * @var list<string>
     */
    public array $gambarEkstensi = ['jpg', 'jpeg', 'png', 'gif'];

    /** Banner: rasio lebar:tinggi wajib 4:1 dengan toleransi relatif (0,02 = 2%). */
    public float $bannerRasio = 4.0;

    public float $bannerToleransi = 0.02;

    /** Lebar banner (px) yang diterima. */
    public int $bannerLebarMin = 800;

    public int $bannerLebarMaks = 4000;

    /** Popup: sisi terpendek/terpanjang (px) yang diterima; rasio bebas. */
    public int $popupSisiMin = 200;

    public int $popupSisiMaks = 4000;

    /**
     * Berita (F-07): role 3 mengubah berita → status kembali menunggu persetujuan? Keputusan Horii (10-11): FALSE = langsung berlaku
     * seperti legacy [K] — form ubah legacy tidak punya field status, jadi `L_news::update` (:489, `set_param`:877) membiarkan
     * status (berita terbit tetap terbit). true = [V2] opsional (ubah isi wajib persetujuan ulang); saklar dipertahankan agar
     * keputusan bisa dibalik satu nilai, dan kedua nilai diuji.
     */
    public bool $beritaUbahRole3KembaliMenunggu = false;

    /** Akar penyimpanan gambar (privat, di luar webroot); kunci berkas acak (Q7-14). */
    public string $akarPenyimpanan = WRITEPATH . 'uploads/konten';
}
