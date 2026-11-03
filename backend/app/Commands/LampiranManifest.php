<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Lampiran\ManifestLampiran;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use RuntimeException;

/**
 * MAKE-069 §4 — manifest berkas lampiran dari DB v2 SESUDAH impor:
 *
 *   php spark lampiran:manifest --keluaran=<berkas.json> --konfirmasi=<db_tujuan>
 *
 * Berkas keluaran berisi nama berkas nyata: simpan di folder kerja di luar repo dan di luar folder sinkron cloud. Layar
 * hanya menampilkan jumlah per sumber dan jumlah path tidak sah per kolom.
 */
class LampiranManifest extends BaseCommand
{
    protected $group       = 'Lampiran';
    protected $name        = 'lampiran:manifest';
    protected $description = 'Membuat manifest berkas lampiran legacy dari DB v2 hasil impor (MAKE-069).';
    protected $usage       = 'lampiran:manifest --keluaran=<berkas.json> --konfirmasi=<db_tujuan>';
    protected $options     = [
        '--keluaran'   => 'Berkas manifest (folder kerja, di luar repo)',
        '--konfirmasi' => 'Ketik ulang nama database tujuan',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['keluaran' => true, 'konfirmasi' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $keluaran = $opsi['keluaran'] ?? null;

        if ($argumen !== [] || ! is_string($keluaran)) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $db = KoneksiCli::buka();
        CLI::write(sprintf('Tujuan: %s:%s / %s', $db->hostname, (string) $db->port, $db->getDatabase()), 'yellow');

        if (($opsi['konfirmasi'] ?? null) !== $db->getDatabase()) {
            CLI::error('Opsi --konfirmasi harus sama persis dengan nama database tujuan.');

            return EXIT_USER_INPUT;
        }

        try {
            $manifest = ManifestLampiran::bangun($db);
            ManifestLampiran::tulis($keluaran, $manifest);
        } catch (RuntimeException $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        foreach (ManifestLampiran::ringkas($manifest) as $baris) {
            CLI::write($baris);
        }

        CLI::write('Manifest selesai (berisi nama berkas nyata — jangan dibagikan).', 'green');

        return EXIT_SUCCESS;
    }
}
