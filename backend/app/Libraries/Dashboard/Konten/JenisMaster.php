<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten;

use App\Models\BaseAuditableModel;
use App\Models\Konten\JenisDiboModel;
use App\Models\Konten\NewsCategoryModel;

/**
 * Dua master sederhana konten Fase 7 (nama + urutan + status 1/2/10, DBV-032 §3.5/3.8): kategori berita (punya `allow_ap`)
 * dan jenis DIBO (Q7-11). Nama tabel/kolom ⚑ mengikuti draf DBV-032.
 */
enum JenisMaster: string
{
    case BeritaKategori = 'berita-kategori';
    case DiboJenis      = 'dibo-jenis';

    public function tabel(): string
    {
        return $this === self::BeritaKategori ? 'news_category' : 'jenis_dibo';
    }

    public function kolomId(): string
    {
        return $this === self::BeritaKategori ? 'id_news_category' : 'id_jenis_dibo';
    }

    public function kolomNama(): string
    {
        return $this === self::BeritaKategori ? 'category' : 'jenis_dibo';
    }

    public function punyaAllowAp(): bool
    {
        return $this === self::BeritaKategori;
    }

    public function label(): string
    {
        return $this === self::BeritaKategori ? 'Kategori berita' : 'Jenis DIBO';
    }

    public function model(): BaseAuditableModel
    {
        return $this === self::BeritaKategori ? new NewsCategoryModel() : new JenisDiboModel();
    }
}
