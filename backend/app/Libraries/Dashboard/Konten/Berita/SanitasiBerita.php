<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Berita;

use App\Libraries\Html\HtmlSanitizer;

/**
 * Sanitasi isi berita (PRD Fase 7 v2.0 Q7-10, anomali legacy XSS tersimpan). Memakai ulang {@see HtmlSanitizer} (HTMLPurifier,
 * tanpa dependensi baru) dengan whitelist yang SAMA dengan editor FE (`UiTeksSederhana`): `p, br, strong, b, em, i, ul, ol, li,
 * a[href]`. Tanpa atribut lain (style, class, handler on-event, target — jadi `rel=noopener` tidak diperlukan), URI hanya http,
 * https, mailto. Teks di luar tag dipertahankan; tag lain dibuang. Dijalankan saat TULIS (semua GET mengirim konten bersih; FE
 * membersihkan ulang saat render).
 */
final class SanitasiBerita
{
    public const ALLOWED_HTML = 'p,br,strong,b,em,i,ul,ol,li,a[href]';

    private readonly HtmlSanitizer $sanitizer;

    public function __construct(?HtmlSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer(self::ALLOWED_HTML);
    }

    public function bersihkan(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }

    /**
     * Ada teks yang terlihat (bukan hanya tag kosong/spasi) pada HTML hasil {@see self::bersihkan()}.
     */
    public static function adaIsi(string $html): bool
    {
        return HtmlSanitizer::hasVisibleContent(strip_tags($html));
    }

    /**
     * Ringkasan teks polos untuk kartu portal (maks $panjang karakter, dipotong di batas kata bila mungkin).
     */
    public static function ringkasan(string $html, int $panjang = 200): string
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (mb_strlen($teks) <= $panjang) {
            return $teks;
        }

        $potong = mb_substr($teks, 0, $panjang);
        $spasi  = mb_strrpos($potong, ' ');

        return rtrim($spasi !== false && $spasi > $panjang * 0.6 ? mb_substr($potong, 0, $spasi) : $potong) . '…';
    }
}
