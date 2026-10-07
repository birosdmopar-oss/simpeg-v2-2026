<?php

declare(strict_types=1);

/**
 * CR-044 — pembagi shard PHPUnit untuk CI (GitHub Actions). Skrip CLI khusus CI, bukan kode aplikasi; tanpa vendor/.
 *
 * Daftar file test diambil dari testsuite di phpunit.dist.xml (<directory suffix=…>, <file>, <exclude>), jadi selalu
 * sama dengan yang dijalankan `composer test`. File dibagi ke N shard secara deterministik (greedy LPT): urut menurun
 * menurut bobot (ukuran file tanpa CR, ±sebanding jumlah test), seri diputus menurut path; tiap file masuk ke shard
 * dengan beban terkecil (seri → indeks terkecil). Hasil sama di Linux/Windows untuk commit yang sama.
 *
 * Pemakaian (dari folder backend/):
 *   php tests/_support/ci/shard.php <indeks 1..N> <N>   → cetak file shard itu, satu per baris (relatif ke backend/)
 *   php tests/_support/ci/shard.php --verify <N>        → cek setiap file test masuk tepat satu shard & tiap shard
 *                                                         berisi; cetak ringkasan; exit 1 bila tidak lolos
 */
chdir(dirname(__DIR__, 3));

/**
 * @return list<string> file test relatif ke backend/ (separator '/'), terurut
 */
function ciShardTestFiles(string $config): array
{
    $xml = simplexml_load_file($config);
    if ($xml === false) {
        fwrite(STDERR, "Tidak dapat membaca {$config}\n");

        exit(2);
    }

    $norm  = static fn (string $p): string => ltrim(str_replace('\\', '/', preg_replace('#^\./#', '', trim($p)) ?? ''), '/');
    $files = [];

    foreach ($xml->testsuites->testsuite ?? [] as $suite) {
        $excludes = [];

        foreach ($suite->exclude ?? [] as $ex) {
            $excludes[] = rtrim($norm((string) $ex), '/');
        }

        foreach ($suite->directory ?? [] as $dir) {
            $suffix = (string) ($dir['suffix'] ?? '') !== '' ? (string) $dir['suffix'] : 'Test.php';
            $base   = rtrim($norm((string) $dir), '/');
            $it     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

            foreach ($it as $f) {
                /** @var SplFileInfo $f */
                $path = $norm($f->getPathname());
                if (! $f->isFile() || ! str_ends_with($path, $suffix)) {
                    continue;
                }

                foreach ($excludes as $ex) {
                    if ($path === $ex || str_starts_with($path, $ex . '/')) {
                        continue 2;
                    }
                }
                $files[$path] = true;
            }
        }

        foreach ($suite->file ?? [] as $file) {
            $files[$norm((string) $file)] = true;
        }
    }

    $list = array_keys($files);
    sort($list, SORT_STRING);

    return $list;
}

/**
 * @param list<string> $files
 *
 * @return list<list<string>> shard ke-0..N-1, isi tiap shard terurut path
 */
function ciShardSplit(array $files, int $total): array
{
    $weighted = [];

    foreach ($files as $f) {
        $weighted[] = [strlen(str_replace("\r", '', (string) file_get_contents($f))), $f];
    }
    usort($weighted, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

    $load   = array_fill(0, $total, 0);
    $shards = array_fill(0, $total, []);

    foreach ($weighted as [$size, $f]) {
        $idx = (int) array_search(min($load), $load, true);
        $load[$idx] += $size;
        $shards[$idx][] = $f;
    }

    foreach ($shards as &$s) {
        sort($s, SORT_STRING);
    }
    unset($s);

    return $shards;
}

$argv = $_SERVER['argv'] ?? [];

if (($argv[1] ?? '') === '--verify') {
    $total = (int) ($argv[2] ?? 0);
    if ($total < 1) {
        fwrite(STDERR, "Pemakaian: php tests/_support/ci/shard.php --verify <N>\n");

        exit(2);
    }
    $all    = ciShardTestFiles('phpunit.dist.xml');
    $shards = ciShardSplit($all, $total);
    $merged = array_merge(...$shards);
    $dupes  = array_keys(array_filter(array_count_values($merged), static fn (int $c): bool => $c > 1));
    $miss   = array_values(array_diff($all, $merged));
    $extra  = array_values(array_diff($merged, $all));
    $empty  = array_keys(array_filter($shards, static fn (array $s): bool => $s === []));

    foreach ($shards as $i => $s) {
        $bytes = 0;

        foreach ($s as $f) {
            $bytes += strlen(str_replace("\r", '', (string) file_get_contents($f)));
        }
        printf("shard %d/%d: %d file, %d byte\n", $i + 1, $total, count($s), $bytes);
    }
    printf("total: %d file test, %d entri shard, duplikat %d, terlewat %d, asing %d, shard kosong %d\n", count($all), count($merged), count($dupes), count($miss), count($extra), count($empty));

    if ($all === [] || $dupes !== [] || $miss !== [] || $extra !== [] || $empty !== []) {
        fwrite(STDERR, 'CAKUPAN SHARD GAGAL: ' . json_encode(compact('dupes', 'miss', 'extra', 'empty')) . "\n");

        exit(1);
    }
    echo "CAKUPAN SHARD LOLOS: setiap file test tepat satu kali\n";

    exit(0);
}

$index = (int) ($argv[1] ?? 0);
$total = (int) ($argv[2] ?? 0);
if ($total < 1 || $index < 1 || $index > $total) {
    fwrite(STDERR, "Pemakaian: php tests/_support/ci/shard.php <indeks 1..N> <N> | --verify <N>\n");

    exit(2);
}

foreach (ciShardSplit(ciShardTestFiles('phpunit.dist.xml'), $total)[$index - 1] as $f) {
    echo $f, "\n";
}
