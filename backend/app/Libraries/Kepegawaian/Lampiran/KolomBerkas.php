<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Helper kolom berkas (B-18, MAKE-009) untuk tabel yang menyimpan berkas langsung di kolomnya, bukan di
 * `document_attachment` — mis. `riwayat_karpeg`/`riwayat_kariskarsu` `file_1..5` (WS-1 M4), LKH/Konket (WS-2 M5).
 *
 * Validasi sama dengan lampiran (ValidasiBerkas + AturanLampiran), nama/path simpan dibangkitkan server, dan berkas
 * direkonsiliasi dengan DB setelah transaksi (PenjagaBerkas): berkas tetap ada hanya bila `$tabel.$kolom` merujuk
 * path-nya. Pola pakai di dalam transaksi pemanggil:
 *
 *   $kolom = new KolomBerkas();
 *   $db->transException(true)->transStart();
 *   $path = $kolom->simpan($berkas, $aturan, 'riwayat_karpeg', 'file_1', 'file_1');
 *   $kolom->hapus($lama['file_1'], 'riwayat_karpeg', 'file_1');   // berkas lama, bila diganti
 *   $db->table('riwayat_karpeg')->where(...)->update(['file_1' => $path]);
 *   $db->transComplete();
 *   $kolom->selesaikan();   // juga sesudah rollback (di blok catch/finally)
 *
 * Berbeda dengan AttachmentService, helper ini TIDAK merekonsiliasi secara oportunistik: path baru belum dirujuk
 * sampai pemanggil menulis kolomnya, jadi rekonsiliasi hanya saat selesaikan() dipanggil (atau proses berakhir).
 */
final class KolomBerkas
{
    private readonly StorageAdapterInterface $storage;

    private readonly PenjagaBerkas $penjaga;

    public function __construct(?StorageAdapterInterface $storage = null, ?ConnectionInterface $db = null)
    {
        $this->storage = $storage ?? service('storageAdapter');
        $this->penjaga = new PenjagaBerkas($this->storage, $db);
    }

    /**
     * Validasi + tulis berkas; mengembalikan path relatif untuk ditulis pemanggil ke `$tabel.$kolom`.
     *
     * @param string $field kunci error 422 (mis. 'file_1')
     */
    public function simpan(UploadedFile $berkas, AturanLampiran $aturan, string $tabel, string $kolom, string $field = 'berkas'): string
    {
        $valid = ValidasiBerkas::periksa($berkas, $aturan, $field);
        $path  = 'kolom/' . gmdate('Y') . '/' . gmdate('m') . '/' . bin2hex(random_bytes(16)) . '.' . $valid['ekstensi'];

        $this->storage->simpan($path, $valid['isi']);
        $this->penjaga->catat($path, $tabel, $kolom);

        return $path;
    }

    /**
     * Tandai berkas lama untuk dihapus bila setelah transaksi tidak lagi dirujuk `$tabel.$kolom`. Path kosong/null
     * diabaikan.
     */
    public function hapus(?string $path, string $tabel, string $kolom): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->penjaga->catat($path, $tabel, $kolom);
    }

    public function baca(string $path): string
    {
        return $this->storage->baca($path);
    }

    public function ada(string $path): bool
    {
        return $this->storage->ada($path);
    }

    /**
     * Rekonsiliasi berkas tercatat dengan DB; false bila transaksi masih terbuka.
     */
    public function selesaikan(): bool
    {
        return $this->penjaga->selesaikan();
    }
}
