<?php

declare(strict_types=1);

namespace App\Libraries\Html;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_URIDefinition;

/**
 * Sanitasi HTML konten yang ditulis admin (DBV-002 / keputusan U1): whitelist HTMLPurifier saat TULIS, sehingga
 * seluruh GET mengirim konten yang sudah bersih (frontend tetap menyanitasi ulang lewat DOMPurify saat render).
 *
 * Whitelist (setara util sanitizeHtml() frontend):
 *   p, br, strong, b, em, i, u, s, sub, sup, ul, ol, li, a[href|title|target], img[src|alt|width|height],
 *   h2, h3, h4, blockquote, pre, code, hr, table, thead, tbody, tr, th[colspan|rowspan], td[colspan|rowspan], span.
 * Tanpa atribut style/class/id/on*. URI: http, https, mailto (+ URI relatif untuk tautan); src gambar hanya URL
 * absolut http/https (EmbeddedUriSchemeFilter). `target` hanya `_blank` dan otomatis diberi
 * rel="noopener noreferrer".
 *
 * Dipakai sebagai service shared (service('htmlSanitizer')): definisi HTMLPurifier cukup dibangun sekali per proses.
 * Cache definisi ke disk dimatikan (Cache.DefinitionImpl = null) agar tidak bergantung pada folder writable.
 */
class HtmlSanitizer
{
    public const ALLOWED_HTML = 'p,br,strong,b,em,i,u,s,sub,sup,ul,ol,li,a[href|title|target],img[src|alt|width|height],'
        . 'h2,h3,h4,blockquote,pre,code,hr,table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],span';

    private ?HTMLPurifier $purifier = null;

    /**
     * HTML aman sesuai whitelist. Teks di luar tag dipertahankan; tag/atribut di luar whitelist dibuang.
     */
    public function sanitize(string $html): string
    {
        return trim($this->purifier()->purify($html));
    }

    /**
     * Rumus legacy `content_stripped` (L_faq.php:720): teks polos untuk indeks FULLTEXT/pencarian — dihitung dari
     * konten yang SUDAH disanitasi.
     */
    public static function strip(string $html): string
    {
        return trim((string) preg_replace('/\t+/', '', strip_tags($html)));
    }

    /**
     * Apakah HTML (hasil sanitize) punya isi yang terlihat: teks selain spasi/&nbsp;, atau gambar. `<p></p>` atau
     * konten yang seluruhnya tag berbahaya (terbuang saat sanitasi) dianggap kosong.
     */
    public static function hasVisibleContent(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/[^\s\x{00A0}\x{200B}]/u', $text) === 1) {
            return true;
        }

        return stripos($html, '<img') !== false;
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
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);

        $uri = $config->getDefinition('URI');

        if ($uri instanceof HTMLPurifier_URIDefinition) {
            $uri->addFilter(new EmbeddedUriSchemeFilter(), $config);
        }

        return $this->purifier = new HTMLPurifier($config);
    }
}
