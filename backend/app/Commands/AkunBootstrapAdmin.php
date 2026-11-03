<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\BootstrapAdmin;
use App\Libraries\Cli\OpsiCli;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use RuntimeException;

/**
 * MAKE-069 (K-M2) — membuat akun admin awal role 1:
 *
 *   php spark akun:bootstrap-admin --username=<u> --nama=<n> [--email=<e>] --konfirmasi=<db_tujuan>
 *                                  [--tambahan] [--paksa-non-interaktif]
 *
 * Tujuan (host:port / DB) dicetak lebih dulu, lalu `--konfirmasi` wajib sama dengan nama DB. Password acak ditampilkan
 * SEKALI di layar dan tidak pernah ditulis ke log, berkas, atau `audit_logs`; akun wajib ganti password saat login
 * pertama. Tanpa terminal interaktif (keluaran bisa tertangkap log otomatis) ditolak kecuali `--paksa-non-interaktif`.
 * Ditolak bila sudah ada admin role 1 aktif, kecuali `--tambahan`. Runbook cutover: sesudah impor `pengguna`, bila perlu.
 */
class AkunBootstrapAdmin extends BaseCommand
{
    protected $group       = 'Akun';
    protected $name        = 'akun:bootstrap-admin';
    protected $description = 'Membuat akun admin awal role 1 dengan password acak sekali tampil (MAKE-069 K-M2).';
    protected $usage       = 'akun:bootstrap-admin --username=<u> --nama=<n> [--email=<e>] --konfirmasi=<db_tujuan> [--tambahan] [--paksa-non-interaktif]';
    protected $options     = [
        '--username'             => 'Username login admin',
        '--nama'                 => 'Nama tampilan akun',
        '--email'                => 'Email (opsional)',
        '--konfirmasi'           => 'Ketik ulang nama database tujuan',
        '--tambahan'             => 'Izinkan walau sudah ada admin role 1 aktif',
        '--paksa-non-interaktif' => 'Izinkan tanpa terminal interaktif (password tetap hanya ke layar)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, [
                'username' => true, 'nama' => true, 'email' => true, 'konfirmasi' => true, 'tambahan' => false, 'paksa-non-interaktif' => false,
            ]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $username = $opsi['username'] ?? null;
        $nama     = $opsi['nama'] ?? null;
        $email    = $opsi['email'] ?? null;

        if ($argumen !== [] || ! is_string($username) || ! is_string($nama) || ($email !== null && ! is_string($email))) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $db = db_connect();
        CLI::write(sprintf('Tujuan: %s:%s / %s', $db->hostname, (string) $db->port, $db->getDatabase()), 'yellow');

        if (($opsi['konfirmasi'] ?? null) !== $db->getDatabase()) {
            CLI::error('Opsi --konfirmasi harus sama persis dengan nama database tujuan.');

            return EXIT_USER_INPUT;
        }

        if (! self::interaktif() && ! isset($opsi['paksa-non-interaktif'])) {
            CLI::error('Bukan terminal interaktif: password bisa tertangkap log. Jalankan di terminal, atau pakai --paksa-non-interaktif.');

            return EXIT_USER_INPUT;
        }

        try {
            $hasil = (new BootstrapAdmin($db))->buat($username, $nama, $email, isset($opsi['tambahan']));
        } catch (RuntimeException $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Akun admin dibuat (id {$hasil['id']}). Password awal (tampil sekali, wajib diganti saat login pertama):", 'green');
        CLI::write($hasil['password']);

        return EXIT_SUCCESS;
    }

    private static function interaktif(): bool
    {
        return defined('STDOUT') && stream_isatty(STDOUT);
    }
}
