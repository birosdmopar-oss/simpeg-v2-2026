<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Arsip;

/**
 * MAKE-093 (PRD F-10 §7.2, §7.3, §11.1) — pembanding string antara `README-deploy.md` dan `Config\Arsip`:
 *  - dua regex berkas LEGACY di tabel pola §1l dan di blok Nginx `location ^~ /berkas/` (contoh §1) sama persis dengan
 *    `Config\Arsip::REGEX_LEGACY_ARSIP` / `REGEX_LEGACY_ASSETS`, dan blok Nginx tidak memuat pola lain;
 *  - regex kunci v2 (`REGEX_KUNCI_V2`) TIDAK ada di README: berkas unggahan v2 privat lewat endpoint `buka` (F10-18 (b));
 *  - CSP snippet header §1l sama dengan `Config\Arsip::CSP_BERKAS` (endpoint `buka` memakai CSP yang sama, F10-34 (4)).
 *
 * @internal
 */
final class ReadmeBerkasNginxTest extends CIUnitTestCase
{
    /**
     * @return array<string, string> awalan path => regex `Config\Arsip`
     */
    private static function polaLegacy(): array
    {
        return [
            'arsip'         => Arsip::REGEX_LEGACY_ARSIP,
            'assets/upload' => Arsip::REGEX_LEGACY_ASSETS,
        ];
    }

    private function readme(): string
    {
        $path = ROOTPATH . '../README-deploy.md';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function bagian1l(): string
    {
        $this->assertSame(1, preg_match('/^### 1l\. .*?(?=^##? )/ms', $this->readme() . "\n## ", $m), 'Bagian §1l tidak ditemukan');

        return $m[0];
    }

    public function testKonstantaLegacyBerjangkarDanBerawalanBenar(): void
    {
        foreach (self::polaLegacy() as $awalan => $pola) {
            $this->assertStringStartsWith('^' . $awalan . '/', $pola);
            $this->assertStringEndsWith('$', $pola);
        }
    }

    public function testTabelPola1lSamaPersisDenganConfigArsip(): void
    {
        $bagian = $this->bagian1l();

        foreach (self::polaLegacy() as $pola) {
            $this->assertStringContainsString('| `' . $pola . '` |', $bagian, "Regex {$pola} tidak ada (persis) di tabel §1l");
        }
    }

    public function testBlokNginxHanyaDuaPolaLegacySamaPersis(): void
    {
        preg_match_all('/location ~ "\^\/berkas\/\(\?<kunci>(.*?)\)\$" \{/', $this->readme(), $m);

        // Regex Nginx = `^/berkas/(?<kunci>` + pola tanpa jangkar `^`/`$` + `)$`.
        $harapan = array_map(static fn (string $p): string => substr($p, 1, -1), array_values(self::polaLegacy()));
        $nyata   = array_values(array_unique($m[1]));
        sort($harapan);
        sort($nyata);

        $this->assertNotSame([], $m[1], 'Blok Nginx /berkas/ tidak ditemukan di README-deploy');
        $this->assertSame($harapan, $nyata);
    }

    public function testReadmeTidakMemuatPolaKunciV2(): void
    {
        $readme = $this->readme();

        $this->assertStringNotContainsString(Arsip::REGEX_KUNCI_V2, $readme);
        $this->assertStringNotContainsString(substr(Arsip::REGEX_KUNCI_V2, 1, -1), $readme);
        $this->assertStringNotContainsString('[0-9a-f]{32}', $readme);
    }

    public function testCspSnippet1lSamaDenganConfigArsip(): void
    {
        $this->assertStringContainsString(
            'add_header Content-Security-Policy "' . Arsip::CSP_BERKAS . '" always;',
            $this->bagian1l(),
        );
    }
}
