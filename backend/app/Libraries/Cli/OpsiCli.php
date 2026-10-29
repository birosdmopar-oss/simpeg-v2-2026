<?php

declare(strict_types=1);

namespace App\Libraries\Cli;

use InvalidArgumentException;

/**
 * Pengurai opsi command spark (CR-080). Parser CI4 (`CLI::parseCommandLine`, juga helper `command()`) tidak memecah
 * `--k=v`: opsi itu tiba sebagai kunci `"k=v"` bernilai null, sehingga `$params['k']` kosong dan opsi diabaikan diam-diam.
 * Bentuk `--k v` tiba sebagai `['k' => 'v']`. Helper ini menerima kedua bentuk dan menolak (bukan mengabaikan) opsi yang
 * tidak dikenal, opsi bernilai tanpa nilai, bendera yang diberi nilai, opsi ganda, dan argumen yang tertelan sebagai nilai.
 *
 * Catatan parser CI4: kata sesudah opsi yang tidak diawali `-` selalu dibaca sebagai nilai opsi itu. Jadi `--bendera
 * arg` atau `--k=v arg` menelan `arg`; keduanya ditolak di sini dengan saran meletakkan argumen sebelum opsi.
 */
final class OpsiCli
{
    /**
     * @param array<int|string, string|null> $params  `$params` dari `BaseCommand::run()` (segmen berindeks angka + opsi)
     * @param array<string, bool>            $dikenal nama opsi tanpa `--` => true bila bernilai, false bila bendera
     *
     * @return array{argumen: list<string>, opsi: array<string, string|true>} bendera yang hadir bernilai true
     *
     * @throws InvalidArgumentException pesan siap tampil untuk pengguna
     */
    public static function urai(array $params, array $dikenal): array
    {
        $argumen = [];
        $opsi    = [];

        foreach ($params as $kunci => $nilai) {
            if (is_int($kunci)) {
                $argumen[] = (string) $nilai;

                continue;
            }

            $nama = $kunci;

            if (str_contains($kunci, '=')) {
                [$nama, $isi] = explode('=', $kunci, 2);

                if ($nilai !== null) {
                    throw new InvalidArgumentException("Argumen '{$nilai}' terbaca sebagai nilai opsi --{$kunci}; letakkan argumen sebelum opsi.");
                }

                $nilai = $isi;
            }

            if (! array_key_exists($nama, $dikenal)) {
                throw new InvalidArgumentException("Opsi --{$nama} tidak dikenal.");
            }

            if (array_key_exists($nama, $opsi)) {
                throw new InvalidArgumentException("Opsi --{$nama} diberikan lebih dari sekali.");
            }

            if ($dikenal[$nama]) {
                if ($nilai === null || $nilai === '') {
                    throw new InvalidArgumentException("Opsi --{$nama} wajib diberi nilai (--{$nama}=<nilai> atau --{$nama} <nilai>).");
                }

                $opsi[$nama] = $nilai;

                continue;
            }

            if ($nilai !== null) {
                throw new InvalidArgumentException("Opsi --{$nama} tidak menerima nilai ('{$nilai}'); bila itu argumen, letakkan sebelum opsi.");
            }

            $opsi[$nama] = true;
        }

        return ['argumen' => $argumen, 'opsi' => $opsi];
    }
}
