<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\Html\HtmlSanitizer;
use App\Validation\ByteRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * DBV-002 (U1) — whitelist HTMLPurifier untuk isi artikel FAQ, rumus legacy content_stripped, dan rule
 * max_byte_length (TINYTEXT dihitung byte).
 *
 * @internal
 */
final class HtmlSanitizerTest extends CIUnitTestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer();
    }

    public function testWhitelistedMarkupIsKept(): void
    {
        $html = '<h2>Judul</h2><h3>Sub</h3><h4>Kecil</h4><p><strong>a</strong><b>b</b><em>c</em><i>d</i><u>e</u><s>f</s>'
            . 'x<sub>2</sub>y<sup>3</sup><span>g</span><br /></p><ul><li>satu</li></ul><ol><li>dua</li></ol>'
            . '<blockquote>kutip</blockquote><pre><code>kode</code></pre><hr />'
            . '<table><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td rowspan="2">A</td></tr></tbody></table>';

        $this->assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testDangerousMarkupAndAttributesAreRemoved(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p id="x" class="y" style="color:red" onclick="curi()" onmouseover="curi()">Teks<script>alert(1)</script></p>'
            . '<h1>H1</h1><iframe src="https://contoh.id"></iframe><object data="x"></object><style>p{}</style>'
            . '<form action="https://contoh.id"><input name="a"></form>',
        );

        $this->assertSame('<p>Teks</p>H1', $clean);
    }

    public function testLinkSchemesAndTarget(): void
    {
        $this->assertSame('<a>x</a>', $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame('<a>x</a>', $this->sanitizer->sanitize('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'));
        $this->assertSame('<a href="mailto:bantuan@kemenparekraf.go.id">m</a>', $this->sanitizer->sanitize('<a href="mailto:bantuan@kemenparekraf.go.id">m</a>'));
        $this->assertSame('<a href="/faq/2" title="Terkait">r</a>', $this->sanitizer->sanitize('<a href="/faq/2" title="Terkait">r</a>'));

        // target hanya _blank, dan otomatis diberi rel noopener + noreferrer.
        $blank = $this->sanitizer->sanitize('<a href="https://kemenparekraf.go.id" target="_blank" rel="opener">s</a>');
        $this->assertStringContainsString('target="_blank"', $blank);
        $this->assertMatchesRegularExpression('/ rel="(noreferrer noopener|noopener noreferrer)"/', $blank);
        $this->assertSame('<a href="https://kemenparekraf.go.id">s</a>', $this->sanitizer->sanitize('<a href="https://kemenparekraf.go.id" target="_top">s</a>'));
    }

    public function testImageSourceMustBeAbsoluteHttp(): void
    {
        $this->assertSame(
            '<img src="https://contoh.id/a.png" alt="a" width="10" height="20" />',
            $this->sanitizer->sanitize('<img src="https://contoh.id/a.png" alt="a" width="10" height="20" onerror="x()" style="border:0">'),
        );

        foreach (['mailto:a@b.c', 'javascript:alert(1)', '/assets/upload/news/a.png', 'data:image/png;base64,AAAA'] as $src) {
            $this->assertSame('', $this->sanitizer->sanitize("<img src=\"{$src}\" alt=\"a\">"), $src);
        }
    }

    public function testStripUsesLegacyFormula(): void
    {
        $this->assertSame('LangkahBuka menu.', HtmlSanitizer::strip("  <h2>Langkah</h2>\t\t<p>Buka <em>menu</em>.</p>\n"));
    }

    public function testVisibleContentDetection(): void
    {
        $this->assertTrue(HtmlSanitizer::hasVisibleContent('<p>a</p>'));
        $this->assertTrue(HtmlSanitizer::hasVisibleContent('<img src="https://contoh.id/a.png" alt="" />'));
        $this->assertFalse(HtmlSanitizer::hasVisibleContent(''));
        $this->assertFalse(HtmlSanitizer::hasVisibleContent('<p> </p><p>&nbsp;</p><br />'));
        $this->assertFalse(HtmlSanitizer::hasVisibleContent("<p>\u{00A0}\u{200B}</p>"));
    }

    public function testMaxByteLengthCountsBytes(): void
    {
        $rules = new ByteRules();

        $this->assertTrue($rules->max_byte_length(str_repeat('a', 255), '255'));
        $this->assertFalse($rules->max_byte_length(str_repeat('a', 256), '255'));
        // 128 karakter 'é' = 256 byte.
        $this->assertFalse($rules->max_byte_length(str_repeat('é', 128), '255'));
        $this->assertTrue($rules->max_byte_length(str_repeat('é', 127) . 'a', '255'));
        $this->assertTrue($rules->max_byte_length(null, '255'));
        $this->assertTrue($rules->max_byte_length(12345, '5'));
        $this->assertFalse($rules->max_byte_length(['a'], '255'));
        $this->assertFalse($rules->max_byte_length('a', 'x'));
    }
}
