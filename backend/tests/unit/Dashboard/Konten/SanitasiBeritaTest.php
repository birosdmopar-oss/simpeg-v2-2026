<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Konten;

use App\Libraries\Dashboard\Konten\Berita\SanitasiBerita;
use App\Libraries\Html\HtmlSanitizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Sanitasi isi berita (PRD Fase 7 v2.0 Q7-10): memakai ulang HtmlSanitizer dengan whitelist sempit. Membuktikan juga bahwa
 * pemanggil lama HtmlSanitizer (tanpa argumen) TIDAK berubah perilakunya.
 *
 * @internal
 */
final class SanitasiBeritaTest extends CIUnitTestCase
{
    /** Masukan kaya: tag di dalam whitelist default (FAQ/Web Config) maupun di luarnya. */
    private const KAYA = '<h2>Judul</h2><p>Teks <strong>tebal</strong> <em>miring</em> <u>garis</u> <s>coret</s></p>'
        . '<ul><li>satu</li></ul><img src="https://contoh.test/a.png" alt="x"><a href="https://contoh.test" target="_blank" title="t">tautan</a>'
        . '<table><tr><td>sel</td></tr></table><script>alert(1)</script>';

    public function testDefaultHtmlSanitizerTidakBerubah(): void
    {
        // Keluaran default dipatok sebagai nilai tetap (diambil dari perilaku sebelum parameter whitelist ditambahkan).
        $this->assertSame(
            '<h2>Judul</h2><p>Teks <strong>tebal</strong> <em>miring</em> <u>garis</u> <s>coret</s></p>'
            . '<ul><li>satu</li></ul><img src="https://contoh.test/a.png" alt="x" /><a href="https://contoh.test" target="_blank" title="t" rel="noreferrer noopener">tautan</a>'
            . '<table><tr><td>sel</td></tr></table>',
            (new HtmlSanitizer())->sanitize(self::KAYA),
        );
        $this->assertSame((new HtmlSanitizer())->sanitize(self::KAYA), (new HtmlSanitizer(null))->sanitize(self::KAYA));
        $this->assertSame(HtmlSanitizer::ALLOWED_HTML, 'p,br,strong,b,em,i,u,s,sub,sup,ul,ol,li,a[href|title|target],img[src|alt|width|height],h2,h3,h4,blockquote,pre,code,hr,table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],span');
    }

    public function testWhitelistBeritaMembuangTagDiLuarDaftar(): void
    {
        $bersih = (new SanitasiBerita())->bersihkan(self::KAYA);

        $this->assertStringContainsString('<strong>tebal</strong>', $bersih);
        $this->assertStringContainsString('<em>miring</em>', $bersih);
        $this->assertStringContainsString('<li>satu</li>', $bersih);
        $this->assertStringContainsString('<a href="https://contoh.test">tautan</a>', $bersih);
        $this->assertDoesNotMatchRegularExpression('/<(h2|u|s|img|table|td|script)\b|target=|title=|alert/i', $bersih);
        $this->assertStringContainsString('Judul', $bersih, 'teks di luar tag dipertahankan');
    }

    public function testBoleheBolditalicDanListBernomor(): void
    {
        $this->assertSame('<p><b>a</b> <i>b</i></p><ol><li>c</li></ol>', (new SanitasiBerita())->bersihkan('<p><b>a</b> <i>b</i></p><ol><li>c</li></ol>'));
    }

    /**
     * @dataProvider provHrefBerbahaya
     */
    public function testHrefSelainHttpHttpsMailtoDitolak(string $href): void
    {
        $bersih = (new SanitasiBerita())->bersihkan('<p><a href="' . $href . '">klik</a></p>');

        $this->assertStringNotContainsString('href=', $bersih);
        $this->assertStringContainsString('klik', $bersih);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provHrefBerbahaya(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript huruf besar' => ['JaVaScRiPt:alert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'file' => ['file:///etc/passwd'];
    }

    public function testHrefHttpHttpsMailtoDiterima(): void
    {
        foreach (['https://contoh.test/a?b=1', 'http://contoh.test', 'mailto:a@contoh.test'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', (new SanitasiBerita())->bersihkan('<a href="' . $href . '">x</a>'));
        }
    }

    public function testHandlerEventDanStyleDibuang(): void
    {
        $bersih = (new SanitasiBerita())->bersihkan('<p onclick="x()" style="color:red" class="k">Isi</p><a href="https://a.test" onmouseover="y()">t</a>');

        $this->assertDoesNotMatchRegularExpression('/onclick|onmouseover|style=|class=/i', $bersih);
        $this->assertStringContainsString('Isi', $bersih);
    }

    public function testScriptIframeDanTagAktifLainDibuang(): void
    {
        $bersih = (new SanitasiBerita())->bersihkan('<p>awal</p><iframe src="https://jahat.test"></iframe><script>alert(1)</script><object data="x"></object><embed src="x"><form action="x"><input name="a"></form><svg onload="x()"></svg><style>p{color:red}</style><p>akhir</p>');

        $this->assertDoesNotMatchRegularExpression('/<(iframe|script|object|embed|form|input|svg|style)\b|alert|jahat|onload/i', $bersih);
        $this->assertStringContainsString('awal', $bersih);
        $this->assertStringContainsString('akhir', $bersih);
    }

    public function testAdaIsiDanRingkasan(): void
    {
        $this->assertFalse(SanitasiBerita::adaIsi('<p></p>'));
        $this->assertFalse(SanitasiBerita::adaIsi('<p>&nbsp; </p>'));
        $this->assertTrue(SanitasiBerita::adaIsi('<p>a</p>'));
        $this->assertSame('Halo dunia', SanitasiBerita::ringkasan('<p>Halo</p><p>dunia</p>'));
        $this->assertStringEndsWith('…', SanitasiBerita::ringkasan('<p>' . str_repeat('kata ', 100) . '</p>', 50));
    }
}
