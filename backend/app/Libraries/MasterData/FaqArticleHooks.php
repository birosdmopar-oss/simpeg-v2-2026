<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;
use App\Libraries\Html\HtmlSanitizer;

/**
 * Hook tulis master `faq-article` (G-10, DBV-002 E2): isi artikel (`content`, HTML) disanitasi HTMLPurifier
 * (whitelist, lihat HtmlSanitizer) lalu `content_stripped` dihitung dari konten TERSANITASI dengan rumus legacy
 * (L_faq.php:720) untuk indeks FULLTEXT pencarian. `content_stripped` bukan field input.
 */
class FaqArticleHooks implements MasterHooks
{
    public const CONTENT          = 'content';
    public const CONTENT_STRIPPED = 'content_stripped';

    public function derivedColumns(): array
    {
        return [self::CONTENT_STRIPPED];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        // Kolom turunan tidak pernah diambil dari input.
        unset($row[self::CONTENT_STRIPPED]);

        if (! array_key_exists(self::CONTENT, $row)) {
            return $row;
        }

        $content = service('htmlSanitizer')->sanitize((string) $row[self::CONTENT]);

        // Konten yang seluruhnya tag berbahaya/kosong (mis. hanya <script>) habis setelah sanitasi.
        if (! HtmlSanitizer::hasVisibleContent($content)) {
            throw ValidationException::forField(self::CONTENT, 'Isi artikel wajib diisi.');
        }

        $row[self::CONTENT]          = $content;
        $row[self::CONTENT_STRIPPED] = HtmlSanitizer::strip($content);

        return $row;
    }
}
