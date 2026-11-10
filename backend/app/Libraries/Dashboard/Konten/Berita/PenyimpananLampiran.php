<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Berita;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use InvalidArgumentException;

/**
 * Lampiran berita (PRD Fase 7 v2.0 Q7-14; legacy `L_news.php:282-340,489-545`): satu berkas JPG/JPEG/PNG/GIF/PDF ≤ 5 MB
 * (paritas legacy), tipe diperiksa dari ISI berkas lewat {@see ValidasiBerkas}. Disimpan privat dengan kunci acak
 * `berita/<hex32>.<ext>` (perbaikan legacy: `nl_{publish_at}` bisa saling menimpa), dibaca lewat endpoint terautentikasi.
 */
final class PenyimpananLampiran
{
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public const MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];

    private const MAKS_MB = 5;

    private readonly LocalStorageAdapter $adapter;

    public function __construct(?Konten $config = null, ?LocalStorageAdapter $adapter = null)
    {
        $this->adapter = $adapter ?? new LocalStorageAdapter(($config ?? config(Konten::class))->akarPenyimpanan);
    }

    /**
     * @return array{isi: string, ekstensi: string}
     *
     * @throws ValidationException 422 pada field `lampiran`
     */
    public function periksa(UploadedFile $berkas): array
    {
        $hasil = ValidasiBerkas::periksa($berkas, new AturanLampiran(0, false, self::MAKS_MB, self::EKSTENSI), 'lampiran');

        return ['isi' => $hasil['isi'], 'ekstensi' => $hasil['ekstensi']];
    }

    /**
     * @return string kunci relatif untuk kolom `news.lampiran`
     */
    public function simpan(string $isi, string $ekstensi): string
    {
        $kunci = 'berita/' . bin2hex(random_bytes(16)) . '.' . $ekstensi;
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
