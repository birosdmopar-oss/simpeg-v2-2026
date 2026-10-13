<?php

declare(strict_types=1);

namespace Tests\Unit\Pdf;

use App\Interfaces\PdfRendererInterface;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Pdf\TcpdfRenderer;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use InvalidArgumentException;

/**
 * Pembungkus PDF ADR-033 (MAKE-013): keluaran PDF valid (header `%PDF`, judul, banyak halaman, lanskap), kop & kaki,
 * respons unduhan, renderer bisa diganti.
 *
 * @internal
 */
final class TcpdfRendererTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Pdf::reset();

        parent::tearDown();
    }

    public function testRenderPdfValidDenganKopKakiDanBanyakHalaman(): void
    {
        $baris = str_repeat('<tr><td>Kegiatan</td><td>Uraian &amp; hasil</td></tr>', 200);
        $isi   = (new TcpdfRenderer())->render(new DokumenPdf(
            'Laporan Uji',
            "<h3>Judul</h3><table border=\"1\">{$baris}</table>",
            kopHtml: '<b>KEMENTERIAN UJI</b>',
            kakiHtml: 'Halaman kaki',
        ));

        $this->assertStringStartsWith('%PDF-', $isi);
        $this->assertStringContainsString('%%EOF', $isi);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page[^s]/', $isi), 'tabel panjang → lebih dari satu halaman');
        $this->assertStringContainsString('Laporan Uji', $isi);
    }

    public function testLanskapDanOrientasiTidakValid(): void
    {
        $this->assertStringStartsWith('%PDF-', (new TcpdfRenderer())->render(new DokumenPdf('L', '<p>x</p>', orientasi: 'L', ukuran: 'F4')));

        $this->expectException(InvalidArgumentException::class);
        new DokumenPdf('X', '<p>x</p>', orientasi: 'Q');
    }

    public function testResponUnduhanDanRendererBisaDiganti(): void
    {
        $respon = Pdf::respon(new Response(new App()), '%PDF-1.4 x', 'lkh 2026/10.pdf');

        $this->assertSame('application/pdf; charset=UTF-8', $respon->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="lkh_2026_10.pdf"', $respon->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $respon->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('inline;', Pdf::respon(new Response(new App()), 'x', 'a.pdf', true)->getHeaderLine('Content-Disposition'));

        $palsu = new class () implements PdfRendererInterface {
            public function render(DokumenPdf $dokumen): string
            {
                return '%PDF-palsu ' . $dokumen->judul;
            }
        };
        Pdf::pakai($palsu);
        $this->assertSame('%PDF-palsu T', Pdf::renderer()->render(new DokumenPdf('T', '')));
        Pdf::reset();
        $this->assertInstanceOf(TcpdfRenderer::class, Pdf::renderer());
    }
}
