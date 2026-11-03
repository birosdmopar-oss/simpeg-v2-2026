<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Bkn\GalatTokenBkn;
use App\Libraries\Bkn\TokenBkn;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * `php spark integrasi:uji-koneksi <bkn|siasn|esign|fcm>` (PRD Fase 6 BP-11, F6-23): uji koneksi NYATA ke layanan luar —
 * dijalankan MANUAL oleh reviewer di lingkungan yang punya akses (MT-010/DEPLOY-001.1), TIDAK di `check.sh`/CI.
 *
 * - `bkn`  : token e-Kinerja (statis atau SSO) lalu `GET referensi/periode` (hanya baca); dicetak status HTTP + jumlah baris.
 * - `siasn`: token API gateway + token SSO (dua header SIASN); tidak memanggil data pegawai.
 * - `esign`/`fcm`: adapter real menyusul (MAKE-065/MAKE-066).
 *
 * Keluaran tidak pernah memuat token, kredensial, alamat, atau data; rincian kegagalan teredaksi ada di `integrasi_log`.
 * Kode keluar: 0 berhasil, 1 gagal, 3 masukan salah / belum tersedia.
 */
class IntegrasiUjiKoneksi extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'integrasi:uji-koneksi';
    protected $description = 'Uji koneksi nyata ke layanan integrasi (manual, bukan bagian check.sh).';
    protected $usage       = 'integrasi:uji-koneksi <bkn|siasn|esign|fcm>';
    protected $arguments   = ['layanan' => 'bkn | siasn | esign | fcm'];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen] = OpsiCli::urai($params, []);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $layanan = $argumen[0] ?? '';

        if (count($argumen) !== 1 || ! in_array($layanan, ['bkn', 'siasn', 'esign', 'fcm'], true)) {
            CLI::error('Layanan wajib salah satu dari bkn, siasn, esign, fcm. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            return match ($layanan) {
                'bkn'   => $this->bkn(service('tokenBkn')),
                'siasn' => $this->siasn(service('tokenBkn')),
                default => $this->belumTersedia($layanan),
            };
        } catch (KonfigurasiIntegrasiTidakLengkap $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        } catch (GalatTokenBkn|GalatTransport $e) {
            CLI::error("Uji koneksi {$layanan} GAGAL: " . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    private function bkn(TokenBkn $bkn): int
    {
        $bkn->pastikanEkinerja();
        $dasar   = rtrim((string) config('Integrations')->bkn['baseUrlEkinerja'], '/');
        $respons = $bkn->kirimEkinerja(static fn (array $h): Permintaan => new Permintaan('GET', $dasar . '/referensi/periode', 'ekinerja', 'uji_koneksi_periode', header: $h));
        $data    = $respons->json();
        $baris   = is_array($data) && is_array($data['data'] ?? null) ? count($data['data']) : 0;

        if (! $respons->sukses()) {
            CLI::error("Uji koneksi bkn GAGAL: HTTP {$respons->status}.");

            return EXIT_ERROR;
        }

        CLI::write("Uji koneksi bkn OK: token e-Kinerja diperoleh, referensi/periode HTTP {$respons->status}, {$baris} baris.", 'green');

        return EXIT_SUCCESS;
    }

    private function siasn(TokenBkn $bkn): int
    {
        $bkn->pastikanSiasn();
        $bkn->headerSiasn();
        CLI::write('Uji koneksi siasn OK: token API gateway dan token SSO diperoleh.', 'green');

        return EXIT_SUCCESS;
    }

    private function belumTersedia(string $layanan): int
    {
        CLI::error("Uji koneksi {$layanan} belum tersedia: adapter real dikerjakan di " . ($layanan === 'esign' ? 'MAKE-065' : 'MAKE-066') . '.');

        return EXIT_USER_INPUT;
    }
}
