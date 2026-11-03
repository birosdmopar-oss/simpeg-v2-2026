<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Lampiran\ManifestLampiran;
use App\Libraries\Lampiran\PenarikLampiran;
use App\Libraries\Lampiran\TransportCurl;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use RuntimeException;

/**
 * MAKE-069 K-M4 opsi 1 — tarik berkas lampiran legacy lewat URL, tanpa masuk server:
 *
 *   php spark lampiran:tarik --manifest=<berkas.json> [--dasar-arsip=<url>] [--dasar-aplikasi=<url>] [--akar=<folder>]
 *                            [--sumber=arsip|aplikasi|semua] [--jeda-ms=200] [--ulang=2] [--ambang-login=5] [--timeout=60]
 *
 * Alamat dasar diisi OPERATOR saat jalan (opsi, atau env `LAMPIRAN_DASAR_ARSIP` / `LAMPIRAN_DASAR_APLIKASI`); tidak pernah
 * ada di config repo, `.env.example`, log, atau laporan. Satu koneksi berurutan dengan jeda (server produksi). Keluaran
 * hanya jumlah per sumber dan per kolom; manifest diperbarui (sha256 + hasil) agar bisa dilanjutkan dan diulang untuk
 * delta saat freeze. Kode keluar: 0 selesai, 1 berhenti karena butuh login / galat, EXIT_USER_INPUT masukan salah.
 */
class LampiranTarik extends BaseCommand
{
    protected $group       = 'Lampiran';
    protected $name        = 'lampiran:tarik';
    protected $description = 'Menarik berkas lampiran legacy lewat URL ke storage v2 sesuai manifest (MAKE-069).';
    protected $usage       = 'lampiran:tarik --manifest=<berkas.json> [--dasar-arsip=<url>] [--dasar-aplikasi=<url>] [--akar=<folder>] [--sumber=arsip|aplikasi|semua] [--jeda-ms=200] [--ulang=2] [--ambang-login=5] [--timeout=60]';
    protected $options     = [
        '--manifest'       => 'Berkas manifest dari lampiran:manifest',
        '--dasar-arsip'    => 'Alamat dasar server arsip legacy (atau env LAMPIRAN_DASAR_ARSIP)',
        '--dasar-aplikasi' => 'Alamat dasar aplikasi legacy (atau env LAMPIRAN_DASAR_APLIKASI)',
        '--akar'           => 'Akar storage v2 (bawaan WRITEPATH/uploads/lampiran)',
        '--sumber'         => 'arsip, aplikasi, atau semua (bawaan semua)',
        '--jeda-ms'        => 'Jeda antar-permintaan dalam ms (bawaan 200)',
        '--ulang'          => 'Ulang untuk 5xx/galat transport (bawaan 2; 404 tidak diulang)',
        '--ambang-login'   => 'Berhenti setelah n respons halaman login (bawaan 5)',
        '--timeout'        => 'Batas waktu per permintaan dalam detik (bawaan 60)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, [
                'manifest' => true, 'dasar-arsip' => true, 'dasar-aplikasi' => true, 'akar' => true, 'sumber' => true,
                'jeda-ms'  => true, 'ulang' => true, 'ambang-login' => true, 'timeout' => true,
            ]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $manifestPath = $opsi['manifest'] ?? null;
        $sumber       = $opsi['sumber'] ?? 'semua';
        $angka        = [];

        foreach (['jeda-ms' => 200, 'ulang' => 2, 'ambang-login' => 5, 'timeout' => 60] as $k => $bawaan) {
            $v = $opsi[$k] ?? (string) $bawaan;

            if (! is_string($v) || ! ctype_digit($v)) {
                CLI::error("Opsi --{$k} harus bilangan bulat ≥ 0. Pemakaian: " . $this->usage);

                return EXIT_USER_INPUT;
            }

            $angka[$k] = (int) $v;
        }

        if ($argumen !== [] || ! is_string($manifestPath) || ! in_array($sumber, ['arsip', 'aplikasi', 'semua'], true)) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $dasar = [];

        foreach (['arsip' => 'LAMPIRAN_DASAR_ARSIP', 'aplikasi' => 'LAMPIRAN_DASAR_APLIKASI'] as $s => $env) {
            $nilai = $opsi["dasar-{$s}"] ?? (getenv($env) !== false ? getenv($env) : null);

            if (is_string($nilai) && $nilai !== '' && ($sumber === 'semua' || $sumber === $s)) {
                $dasar[$s] = $nilai;
            }
        }

        if ($dasar === []) {
            CLI::error('Alamat dasar belum diisi (--dasar-arsip / --dasar-aplikasi atau env).');

            return EXIT_USER_INPUT;
        }

        $akar = is_string($opsi['akar'] ?? null) ? (string) $opsi['akar'] : WRITEPATH . 'uploads/lampiran';

        try {
            if (! is_dir($akar) && ! @mkdir($akar, 0750, true) && ! is_dir($akar)) {
                throw new RuntimeException('Akar storage tidak dapat dibuat.');
            }

            $manifest = ManifestLampiran::baca($manifestPath);
            // Hanya sumber yang alamat dasarnya diberikan yang ditarik; sisanya tetap di manifest.
            $pilih           = $manifest;
            $pilih['berkas'] = array_filter($manifest['berkas'], static fn (array $b): bool => isset($dasar[$b['sumber']]));

            $penarik = new PenarikLampiran(new TransportCurl($angka['timeout']), $dasar, $akar, $angka['jeda-ms'], $angka['ulang'], max(1, $angka['ambang-login']));
            $simpan  = static function (array $sebagian) use (&$manifest, $manifestPath): void {
                $manifest['berkas'] = $sebagian['berkas'] + $manifest['berkas'];
                ManifestLampiran::tulis($manifestPath, $manifest);
            };
            $laporan = $penarik->tarik($pilih, $simpan);
        } catch (InvalidArgumentException $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_USER_INPUT;
        } catch (RuntimeException $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        foreach ($laporan['per_sumber'] as $s => $hitung) {
            CLI::write("sumber {$s}: " . self::teks($hitung));
        }

        foreach ($laporan['per_kolom'] as $label => $hitung) {
            CLI::write("{$label}: " . self::teks($hitung));
        }

        if ($laporan['berhenti']) {
            CLI::error('BERHENTI: respons halaman login mencapai ambang — URL legacy tampaknya butuh login. Laporkan ke Horii/TL.');

            return EXIT_ERROR;
        }

        CLI::write('Penarikan selesai. Jalankan lampiran:periksa.', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @param array<string, int> $hitung
     */
    private static function teks(array $hitung): string
    {
        $out = [];

        foreach (PenarikLampiran::HASIL as $k) {
            $out[] = "{$k}=" . ($hitung[$k] ?? 0);
        }

        return implode(' ', $out);
    }
}
