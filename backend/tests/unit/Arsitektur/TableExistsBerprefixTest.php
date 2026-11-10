<?php

declare(strict_types=1);

namespace Tests\Unit\Arsitektur;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CR-113 — guard: setiap panggilan `->tableExists(` di app/ wajib berbentuk `tableExists(<…prefixTable(…)…>, false)`.
 *
 * Mode cache CI4 membandingkan dengan `dataCache['table_names']` yang bisa basi (terisi saat migrasi awal; tabel dari
 * `CREATE TABLE` SQL mentah tidak pernah masuk), dan mode `false` menjalankan `SHOW TABLES LIKE '<nama>'` TANPA prefix.
 * Insiden: CI #711 (F-08 `SumberShowNotif`/`HistoriApproval`, 6 failure hanya pada proses pertama di DB baru). Alternatif
 * yang juga sah: `information_schema` dengan `prefixTable()` (pola `NipCascadeService`, `Jabatan::namaKementerian`).
 *
 * @internal
 */
final class TableExistsBerprefixTest extends CIUnitTestCase
{
    public function testTableExistsDiAppBerprefixDanTanpaCache(): void
    {
        $diperiksa = 0;

        foreach ($this->berkas(APPPATH) as $path) {
            $kode = $this->kodeTanpaKomentar((string) file_get_contents($path));
            $pos  = 0;

            while (($i = stripos($kode, '->tableexists(', $pos)) !== false) {
                $pos = $i + 1;

                // `$this->tableExists(...)` = metode pembungkus milik kelas itu sendiri (mis. AturanLokasiPresensiHooks),
                // yang isinya diperiksa sebagai panggilan koneksi; hanya panggilan pada koneksi DB yang diperiksa.
                if (substr($kode, max(0, $i - 5), 5) === '$this') {
                    continue;
                }

                $panggilan = $this->argumen($kode, $i + strlen('->tableexists('));
                $diperiksa++;
                $this->assertMatchesRegularExpression(
                    '/prefixtable\(.*\)\s*,\s*false\s*$/s',
                    $panggilan,
                    "{$path}: tableExists({$panggilan}) wajib tableExists(\$db->prefixTable(...), false) (CR-113)",
                );
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'guard harus menemukan pemanggilan tableExists di app/');
    }

    /**
     * Isi argumen sampai kurung penutup yang berpasangan.
     */
    private function argumen(string $kode, int $mulai): string
    {
        $dalam = 1;

        for ($j = $mulai, $n = strlen($kode); $j < $n; $j++) {
            $dalam += match ($kode[$j]) {
                '('     => 1,
                ')'     => -1,
                default => 0,
            };

            if ($dalam === 0) {
                return substr($kode, $mulai, $j - $mulai);
            }
        }

        return substr($kode, $mulai);
    }

    /**
     * @return list<string>
     */
    private function berkas(string $akar): array
    {
        $hasil = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $hasil[] = $f->getPathname();
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
