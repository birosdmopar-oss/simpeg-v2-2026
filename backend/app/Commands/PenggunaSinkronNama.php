<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Kepegawaian\Pegawai\SinkronNamaPengguna;
use App\Libraries\Kepegawaian\Pegawai\SinkronNamaSibuk;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Sinkron nama pengguna (F6-21 bersyarat, MAKE-062; padanan legacy `Cron.php:2818` `sync_nama_pengguna`):
 *
 *   php spark pengguna:sinkron-nama [--dry-run]
 *
 * Menyamakan `pengguna.name` akun pegawai (role 2) dengan nama bergelar di `pegawai`. Idempoten; jadwal USULAN di ADR-037
 * (PN-5). Kode keluar ≠ 0 bila ada akun gagal diproses. Keluaran hanya jumlah, tanpa NIP/nama.
 */
class PenggunaSinkronNama extends BaseCommand
{
    protected $group       = 'Pengguna';
    protected $name        = 'pengguna:sinkron-nama';
    protected $description = 'Menyamakan pengguna.name akun pegawai dengan nama bergelar di data pegawai; idempoten.';
    protected $usage       = 'pengguna:sinkron-nama [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Hanya melaporkan jumlah yang akan diubah, tanpa menulis',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        if ($argumen !== []) {
            CLI::error('Argumen berlebih. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $dryRun = isset($opsi['dry-run']);

        try {
            $db     = KoneksiCli::buka();
            $pelaku = $dryRun ? null : (new AkunSistemIntegrasi($db))->id();
            $hasil  = (new SinkronNamaPengguna($db))->jalankan($dryRun, $pelaku);
        } catch (AkunSistemTidakSiap|SinkronNamaSibuk $e) {
            // Pesan kelas-kelas ini disusun aplikasi tanpa NIP/nilai rahasia.
            log_message('error', 'pengguna:sinkron-nama gagal: ' . (new ReflectionClass($e))->getShortName() . '.');
            CLI::error('Sinkron nama GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            // Pesan galat DB bisa memuat nama/NIP → hanya kelas & kode.
            log_message('error', 'pengguna:sinkron-nama gagal: ' . (new ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');
            CLI::error('Sinkron nama GAGAL: ' . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        $i = $hasil['info'];

        $ringkas = sprintf(
            '%sSinkron nama pengguna: diperiksa %d, %s %d, sudah sama %d, gagal %d. INFO: nama dipotong 150 karakter %d, nama pegawai kosong %d, berubah saat proses %d.',
            $dryRun ? '[dry-run] ' : '',
            $hasil['diperiksa'],
            $dryRun ? 'akan berubah' : 'berubah',
            $hasil['berubah'],
            $hasil['sama'],
            $hasil['gagal'],
            $i['dipotong'],
            $i['nama_kosong'],
            $i['berubah_saat_proses'],
        );

        CLI::write($ringkas);
        log_message('info', 'pengguna:sinkron-nama ' . $ringkas);

        if ($hasil['gagal'] > 0) {
            CLI::error('Ada akun yang gagal diproses; jalankan ulang (idempoten).');

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
