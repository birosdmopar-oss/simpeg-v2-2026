<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\AkunSistemMenyimpang;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Menyiapkan akun sistem integrasi (DBV-027 §4, keputusan DBV Z-1 opsi c):
 *
 *   php spark integrasi:siapkan-akun-sistem
 *
 * Runbook cutover: SESUDAH impor `pengguna`, SEBELUM cron integrasi aktif; lalu audit B1 (WAJIB_0) + B1b (INFO). Dev/demo:
 * sekali setelah migrate. Idempoten: dibuat → exit 0; sudah ada & sesuai → exit 0 tanpa perubahan; sudah ada tetapi
 * menyimpang → EXIT_ERROR dengan nama kolom (tanpa nilai), tidak diperbaiki otomatis.
 */
class IntegrasiSiapkanAkunSistem extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'integrasi:siapkan-akun-sistem';
    protected $description = 'Membuat akun sistem integrasi (sistem.integrasi) bila belum ada; idempoten, menolak akun menyimpang.';
    protected $usage       = 'integrasi:siapkan-akun-sistem';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen] = OpsiCli::urai($params, []);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        if ($argumen !== []) {
            CLI::error('Command ini tanpa argumen. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $hasil = (new AkunSistemIntegrasi(db_connect()))->siapkan();
        } catch (AkunSistemMenyimpang $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write($hasil === AkunSistemIntegrasi::HASIL_DIBUAT ? 'Akun sistem integrasi dibuat.' : 'Akun sistem integrasi sudah ada dan sesuai; tidak ada perubahan.');

        return EXIT_SUCCESS;
    }
}
