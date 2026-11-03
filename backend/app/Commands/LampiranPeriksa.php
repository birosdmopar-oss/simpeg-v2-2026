<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Lampiran\ManifestLampiran;
use App\Libraries\Lampiran\PemeriksaLampiran;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use RuntimeException;

/**
 * MAKE-069 §4 — cek berkas lampiran di storage v2 terhadap manifest:
 *
 *   php spark lampiran:periksa --manifest=<berkas.json> [--akar=<folder>]
 *
 * Keluaran hanya jumlah per `tabel.kolom`: ada, hilang, beda_checksum. Kode keluar 1 bila ada yang hilang/beda.
 */
class LampiranPeriksa extends BaseCommand
{
    protected $group       = 'Lampiran';
    protected $name        = 'lampiran:periksa';
    protected $description = 'Memeriksa berkas lampiran di storage v2 terhadap manifest (MAKE-069).';
    protected $usage       = 'lampiran:periksa --manifest=<berkas.json> [--akar=<folder>]';
    protected $options     = [
        '--manifest' => 'Berkas manifest dari lampiran:manifest / lampiran:tarik',
        '--akar'     => 'Akar storage v2 (bawaan WRITEPATH/uploads/lampiran)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['manifest' => true, 'akar' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $manifestPath = $opsi['manifest'] ?? null;

        if ($argumen !== [] || ! is_string($manifestPath)) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $hasil = PemeriksaLampiran::periksa(ManifestLampiran::baca($manifestPath), is_string($opsi['akar'] ?? null) ? (string) $opsi['akar'] : WRITEPATH . 'uploads/lampiran');
        } catch (RuntimeException $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $kurang = 0;

        foreach ($hasil as $label => $h) {
            CLI::write(sprintf('%s: ada=%d hilang=%d beda_checksum=%d', $label, $h['ada'], $h['hilang'], $h['beda_checksum']));
            $kurang += $h['hilang'] + $h['beda_checksum'];
        }

        if ($kurang > 0) {
            CLI::error("Ada {$kurang} rujukan berkas hilang/beda checksum.");

            return EXIT_ERROR;
        }

        CLI::write('Semua berkas manifest ada di storage.', 'green');

        return EXIT_SUCCESS;
    }
}
