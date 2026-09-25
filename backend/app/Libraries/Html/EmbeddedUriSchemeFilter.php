<?php

declare(strict_types=1);

namespace App\Libraries\Html;

use HTMLPurifier_URIFilter;

/**
 * Filter URI HTMLPurifier: URI yang MEMUAT sumber daya (src gambar) hanya boleh URL absolut http/https.
 * mailto: dan URI relatif (tanpa scheme) ditolak → atribut src dibuang (img tanpa src ikut dibuang HTMLPurifier).
 * Tautan (<a href>) tidak terpengaruh: tetap mengikuti URI.AllowedSchemes (http, https, mailto) + URI relatif.
 */
class EmbeddedUriSchemeFilter extends HTMLPurifier_URIFilter
{
    /**
     * @var string
     */
    public $name = 'SimpegEmbeddedUriScheme';

    /**
     * @param \HTMLPurifier_URI     $uri
     * @param \HTMLPurifier_Config  $config
     * @param \HTMLPurifier_Context $context
     *
     * @return bool
     */
    public function filter(&$uri, $config, $context)
    {
        if ($context->get('EmbeddedURI', true) !== true) {
            return true;
        }

        return in_array(strtolower((string) $uri->scheme), ['http', 'https'], true);
    }
}
