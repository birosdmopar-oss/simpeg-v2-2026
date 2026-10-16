<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Pdf;

use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\TcpdfRenderer;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * CR-050 (QA B-F2): render PDF lewat TcpdfRenderer tidak lagi memicu deprecation "TCPDF is deprecated" (yang ditulis CI4
 * sebagai WARNING log setiap render), tanpa meredam deprecation lain. Proses terpisah: notice TCPDF hanya muncul sekali
 * per proses (static di konstruktor), jadi test lain di proses yang sama bisa menyembunyikannya.
 *
 * @internal
 */
final class TcpdfRendererDeprecationTest extends CIUnitTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRenderTanpaDeprecationTcpdfTetapiDeprecationLainTetapTerlihat(): void
    {
        $tertangkap = [];
        set_error_handler(static function (int $errno, string $pesan) use (&$tertangkap): bool {
            $tertangkap[] = $pesan;

            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            $isi = (new TcpdfRenderer())->render(new DokumenPdf('Uji', '<p>Halo</p>'));
            @trigger_error('Deprecation lain', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
        }

        $this->assertStringStartsWith('%PDF-', $isi);
        $this->assertSame([], array_values(array_filter($tertangkap, static fn (string $p): bool => str_contains($p, 'TCPDF is deprecated'))));
        $this->assertContains('Deprecation lain', $tertangkap, 'peredaman terarah: deprecation lain tidak ikut diredam');
    }
}
