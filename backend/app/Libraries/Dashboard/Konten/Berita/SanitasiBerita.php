<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Berita;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitasi isi berita (PRD Fase 7 v2.0 Q7-10, anomali legacy XSS tersimpan): whitelist HTMLPurifier SAMA dengan editor FE
 * (`UiTeksSederhana`): `p, br, strong, em, ul, ol, li, a[href]`. Tanpa atribut lain (style, class, handler on-event, target), URI hanya
 * http, https, mailto. Teks di luar tag dipertahankan; tag lain dibuang. Dijalankan saat TULIS (semua GET mengirim konten
 * bersih; FE membersihkan ulang saat render).
 */
final class SanitasiBerita
{
    public const ALLOWED_HTML = 'p,br,strong,em,ul,ol,li,a[href]';

    private ?HTMLPurifier $purifier = null;

    public function bersihkan(string $html): string
    {
        return trim($this->purifier()->purify($html));
    }

    /**
     * Ada teks yang terlihat (bukan hanya tag kosong/spasi) pada HTML hasil {@see self::bersihkan()}.
     */
    public static function adaIsi(string $html): bool
    {
        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('/[^\s\x{00A0}\x{200B}]/u', $teks) === 1;
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

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('AutoFormat.RemoveEmpty', true);

        return $this->purifier = new HTMLPurifier($config);
    }
}
