<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Dibo;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use InvalidArgumentException;

/**
 * Berkas DIBO `file_1..3` (PRD Fase 7 v2.0 Q7-14; legacy `L_dibo.php:77-270,598-608`): PDF/XLS/XLSX/DOC/DOCX ≤ 5 MB, tipe dari
 * ISI berkas lewat {@see ValidasiBerkas}. Disimpan PRIVAT (legacy: `assets/upload/dibo` publik di webroot) dengan kunci acak
 * `dibo/<hex32>.<ext>`; dibaca lewat endpoint terautentikasi.
 */
final class PenyimpananDibo
{
    public const EKSTENSI = ['pdf', 'xls', 'xlsx', 'doc', 'docx'];

    public const MIME = [
        'pdf'  => 'application/pdf',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAKS_MB = 5;

    private readonly LocalStorageAdapter $adapter;

    public function __construct(?Konten $config = null, ?LocalStorageAdapter $adapter = null)
    {
        $this->adapter = $adapter ?? new LocalStorageAdapter(($config ?? config(Konten::class))->akarPenyimpanan);
    }

    /**
     * @return array{isi: string, ekstensi: string}
     *
     * @throws ValidationException 422 pada field `$field` (mis. `file_1`)
     */
    public function periksa(UploadedFile $berkas, string $field): array
    {
        $hasil = ValidasiBerkas::periksa($berkas, new AturanLampiran(0, false, self::MAKS_MB, self::EKSTENSI), $field);

        return ['isi' => $hasil['isi'], 'ekstensi' => $hasil['ekstensi']];
    }

    public function simpan(string $isi, string $ekstensi): string
    {
        $kunci = 'dibo/' . bin2hex(random_bytes(16)) . '.' . $ekstensi;
        $this->adapter->simpan($kunci, $isi);

        return $kunci;
    }

    /**
     * Kunci tidak valid (mis. `../`, segmen terlarang, byte nol — hanya mungkin bila kolom di DB rusak/diubah) = berkas tidak ada,
     * bukan galat 500: adapter melempar InvalidArgumentException untuk path di luar aturannya.
     */
    public function ada(string $kunci, string $jenis = '', int $id = 0): bool
    {
        try {
            return $this->adapter->ada($kunci);
        } catch (InvalidArgumentException) {
            // Tanda data diubah: catat HANYA jenis konten + id baris (bukan isi kunci/path, agar log tidak memuat masukan berbahaya).
            if ($jenis !== '') {
                log_message('warning', 'Kunci berkas {jenis} tidak valid (data diubah?): id={id}', ['jenis' => $jenis, 'id' => $id]);
            }

            return false;
        }
    }

    public function baca(string $kunci): string
    {
        return $this->adapter->baca($kunci);
    }

    public function hapus(string $kunci): void
    {
        $this->adapter->hapus($kunci);
    }
}
