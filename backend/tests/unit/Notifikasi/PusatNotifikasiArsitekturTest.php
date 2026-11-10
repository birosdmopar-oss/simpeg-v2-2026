<?php

declare(strict_types=1);

namespace Tests\Unit\Notifikasi;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * BP8-1 (PRD Fase 8; PRD Fase 7 v2.0 §5.9): modul Pusat Notifikasi tidak membaca tabel/kode tiket Halo Simpeg — angka tiket
 * hanya lewat RingkasanTiketInterface — dan tidak membaca tabel berita langsung (sumber berita lewat
 * SumberNotifikasiInterface, PRD §7 A ↔ C). Yang diperiksa hanya kode (komentar/docblock dibuang).
 *
 * @internal
 */
final class PusatNotifikasiArsitekturTest extends CIUnitTestCase
{
    private const FOLDER = [
        'app/Libraries/Notifikasi',
        'app/Controllers/Api/Notifikasi',
        'app/Interfaces/Notifikasi',
    ];

    private const TERLARANG = ['disiplin_ticket', 'ticket', 'halosimpeg', 'halo_simpeg', "'news'", 'news_flag', 'question_category'];

    public function testModulNotifikasiTanpaDependensiTiketDanTabelBerita(): void
    {
        $berkas = $this->berkasModul();
        $this->assertNotEmpty($berkas);

        foreach ($berkas as $path) {
            $kode = $this->kodeTanpaKomentar((string) file_get_contents($path));

            foreach (self::TERLARANG as $kata) {
                $this->assertStringNotContainsString($kata, $kode, "{$path} memuat '{$kata}'");
            }
        }
    }

    public function testRouteNotifikasiTanpaDependensiTiket(): void
    {
        $kode = $this->kodeTanpaKomentar((string) file_get_contents(APPPATH . 'Config/RoutesNotifikasi.php'));

        $this->assertStringNotContainsString('ticket', $kode);
        $this->assertStringNotContainsString('halosimpeg', $kode);
    }

    /**
     * @return list<string>
     */
    private function berkasModul(): array
    {
        $akar  = dirname(APPPATH) . DIRECTORY_SEPARATOR;
        $hasil = [];

        foreach (self::FOLDER as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar . $folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $hasil[] = $f->getPathname();
                }
            }
        }

        sort($hasil);

        return $hasil;
    }

    private function kodeTanpaKomentar(string $sumber): string
    {
        $hasil = '';

        foreach (token_get_all($sumber) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $hasil .= $token[1];
            } else {
                $hasil .= $token;
            }
        }

        return strtolower($hasil);
    }
}
