<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan berkas lampiran di disk lokal (B-18, MAKE-009). Satu-satunya adapter Fase 3: arsip remote legacy tidak
 * disalin (repo publik); adapter remote menyusul di Fase 6 di balik StorageAdapterInterface yang sama.
 *
 * Akar default `writable/uploads/lampiran` (sudah diabaikan git). Path relatif hanya boleh berisi segmen
 * `[A-Za-z0-9._-]` tanpa `.`/`..`, sehingga tidak pernah keluar dari akar. Tulis atomik: berkas sementara di folder
 * tujuan lalu rename.
 */
final class LocalStorageAdapter implements StorageAdapterInterface
{
    private readonly string $akar;

    public function __construct(?string $akar = null)
    {
        $akar = rtrim($akar ?? WRITEPATH . 'uploads/lampiran', '/\\');

        if ($akar === '') {
            throw new InvalidArgumentException('Akar penyimpanan lampiran tidak boleh kosong.');
        }

        $this->akar = $akar;
    }

    public function simpan(string $path, string $isi): void
    {
        $tujuan = $this->lengkap($path);
        $folder = dirname($tujuan);

        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            throw new RuntimeException('Folder penyimpanan lampiran tidak dapat dibuat.');
        }

        $sementara = @tempnam($folder, '.unggah-');

        if ($sementara === false) {
            throw new RuntimeException('Berkas lampiran tidak dapat ditulis.');
        }

        if (@file_put_contents($sementara, $isi) !== strlen($isi) || ! @rename($sementara, $tujuan)) {
            @unlink($sementara);

            throw new RuntimeException('Berkas lampiran tidak dapat ditulis.');
        }

        @chmod($tujuan, 0640);
    }

    public function hapus(string $path): void
    {
        $lengkap = $this->lengkap($path);

        if (is_file($lengkap) && ! @unlink($lengkap) && is_file($lengkap)) {
            throw new RuntimeException('Berkas lampiran tidak dapat dihapus.');
        }
    }

    public function ada(string $path): bool
    {
        return is_file($this->lengkap($path));
    }

    public function baca(string $path): string
    {
        $lengkap = $this->lengkap($path);

        if (! is_file($lengkap)) {
            throw new RuntimeException('Berkas lampiran tidak ditemukan di penyimpanan.');
        }

        $isi = @file_get_contents($lengkap);

        if ($isi === false) {
            throw new RuntimeException('Berkas lampiran tidak dapat dibaca.');
        }

        return $isi;
    }

    /**
     * Path absolut untuk $path relatif; menolak path yang bisa keluar dari akar.
     */
    private function lengkap(string $path): string
    {
        $segmen = explode('/', $path);

        foreach ($segmen as $s) {
            if ($s === '' || $s === '.' || $s === '..' || preg_match('/^[A-Za-z0-9._-]+$/', $s) !== 1) {
                throw new InvalidArgumentException('Path lampiran tidak valid.');
            }
        }

        return $this->akar . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segmen);
    }
}
