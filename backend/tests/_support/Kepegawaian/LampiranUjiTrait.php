<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use CodeIgniter\HTTP\Files\UploadedFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Helper test lampiran B-18 (MAKE-009): akar penyimpanan sementara, berkas unggahan sintetis (PDF/PNG dikenali dari
 * isinya), dan baris riwayat pendidikan sebagai pemilik `id_entri`. Panggil bersihkanLampiranUji() di tearDown().
 */
trait LampiranUjiTrait
{
    private ?string $akarLampiranUji = null;

    /**
     * @var list<string>
     */
    private array $berkasSementaraUji = [];

    protected function akarLampiranUji(): string
    {
        return $this->akarLampiranUji ??= sys_get_temp_dir() . '/simpeg-lampiran-uji-' . bin2hex(random_bytes(6));
    }

    /**
     * Berkas PDF sintetis (magic `%PDF-`) berukuran ± $ukuran byte.
     */
    protected function berkasPdf(string $namaKlien = 'ijazah.pdf', int $ukuran = 2048): UploadedFile
    {
        $isi = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $isi .= str_repeat('0', max(0, $ukuran - strlen($isi) - 6)) . "\n%%EOF";

        return $this->berkasUji($isi, $namaKlien, 'application/pdf');
    }

    /**
     * Berkas PNG asli (1×1) — dipakai untuk menguji bahwa jenis dibaca dari isi, bukan dari nama/MIME kiriman klien.
     */
    protected function berkasPng(string $namaKlien = 'foto.png'): UploadedFile
    {
        $isi = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        return $this->berkasUji($isi, $namaKlien, 'application/pdf');
    }

    protected function berkasUji(string $isi, string $namaKlien, string $mimeKlien, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'unggah');

        if ($path === false || file_put_contents($path, $isi) === false) {
            throw new RuntimeException('Berkas uji gagal dibuat.');
        }

        $this->berkasSementaraUji[] = $path;

        return new UploadedFile($path, $namaKlien, $mimeKlien, strlen($isi), $error);
    }

    /**
     * Baris `riwayat_pendidikan` milik $nip (status 1) sebagai pemilik `id_entri`.
     */
    protected function buatRiwayatPendidikanUji(string $nip): int
    {
        $this->db->table('riwayat_pendidikan')->insert(['nip' => $nip, 'tgl_lulus' => '2012-08-30', 'status' => 1]);

        return (int) $this->db->insertID();
    }

    protected function bersihkanLampiranUji(): void
    {
        foreach ($this->berkasSementaraUji as $path) {
            @unlink($path);
        }

        $this->berkasSementaraUji = [];

        if ($this->akarLampiranUji !== null && is_dir($this->akarLampiranUji)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->akarLampiranUji, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->akarLampiranUji);
        }

        $this->akarLampiranUji = null;
    }

    /**
     * @return list<string> path relatif semua berkas di akar uji
     */
    protected function berkasDiAkarUji(): array
    {
        $akar = $this->akarLampiranUji();

        if (! is_dir($akar)) {
            return [];
        }

        $hasil = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile()) {
                $hasil[] = substr($item->getPathname(), strlen($akar) + 1);
            }
        }

        sort($hasil);

        return $hasil;
    }
}
