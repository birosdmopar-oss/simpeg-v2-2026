<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Models\Kepegawaian\DocumentAttachmentModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lampiran riwayat B-18 (`document_attachment`) — implementasi nyata WS-2 (MAKE-009) untuk AttachmentServiceInterface.
 *
 * Otorisasi (Definisi pemilik kode × role, PegawaiScope, keterikatan id_entri) ditegakkan PEMANGGIL sebelum memanggil
 * service ini (kontrak interface; untuk endpoint lihat OtorisasiLampiran).
 *
 * Transaksi: simpan()/hapus() tidak membuka/commit transaksi. Bila dipanggil di dalam transaksi pemanggil, berkas di
 * disk direkonsiliasi setelah transaksi selesai oleh PenjagaBerkas (rollback → berkas unggahan dihapus, berkas yang
 * "dihapus" dipertahankan). Pemanggil boleh memanggil selesaikan() sesudah commit/rollback; bila tidak, rekonsiliasi
 * terjadi pada operasi lampiran berikutnya atau saat proses PHP berakhir.
 *
 * Baris yang dikembalikan = kolom DDL apa adanya KECUALI `path` (path penyimpanan internal tidak pernah keluar).
 */
final class AttachmentService implements AttachmentServiceInterface
{
    /**
     * Kolom internal yang tidak dikirim ke pemanggil/klien.
     *
     * @var list<string>
     */
    public const KOLOM_INTERNAL = ['path'];

    private readonly PenjagaBerkas $penjaga;

    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly ?ConnectionInterface $db = null,
        ?PenjagaBerkas $penjaga = null,
    ) {
        $this->penjaga = $penjaga ?? new PenjagaBerkas($storage, $db);
    }

    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
    {
        if ($aturan->idRiwayat !== $idRiwayat) {
            throw new InvalidArgumentException('Aturan lampiran tidak sesuai dengan kode jenis_rwy.');
        }

        $this->penjaga->selesaikan();

        $jenis = $this->koneksi()->table('jenis_rwy')->select('jenis_rwy')->where('id_jenis_rwy', $idRiwayat)->get()->getRowArray();

        if (! is_array($jenis)) {
            throw new NotFoundException('Jenis lampiran tidak dikenal.');
        }

        $valid    = ValidasiBerkas::periksa($berkas, $aturan);
        $basename = bin2hex(random_bytes(16)) . '.' . $valid['ekstensi'];
        $path     = gmdate('Y') . '/' . gmdate('m') . '/' . $basename;

        $this->storage->simpan($path, $valid['isi']);
        $this->penjaga->catat($path, 'document_attachment', 'path');

        try {
            $model = $this->model();
            $id    = $model->insert([
                'NIP'          => $nip,
                'filename'     => $basename,
                'id_riwayat'   => $idRiwayat,
                'nama_riwayat' => (string) $jenis['jenis_rwy'],
                'id_entri'     => (string) $idEntri,
                'path'         => $path,
                'basename'     => $basename,
                'display_name' => $valid['nama_tampil'],
                'file_size'    => $valid['ukuran'],
                'file_ext'     => $valid['ekstensi'],
                'file_type'    => $valid['mime'],
            ]);

            if ($id === false) {
                throw new RuntimeException('Baris lampiran gagal disimpan.');
            }
        } catch (Throwable $e) {
            // Tidak ada baris yang merujuk berkas ini: hapus sekarang, tanpa menunggu transaksi pemanggil.
            $this->hapusBerkasDiam($path);

            throw $e;
        }

        if (! $this->penjaga->dalamTransaksi()) {
            $this->penjaga->selesaikan();
        }

        return $this->baris((int) $id) ?? throw new RuntimeException('Baris lampiran tidak terbaca setelah disimpan.');
    }

    public function ambil(string $nip, int $idAttachment): array
    {
        $row = $this->milik($nip, $idAttachment);

        try {
            $isi = $this->storage->baca((string) $row['path']);
        } catch (Throwable $e) {
            log_message('error', 'Berkas lampiran {id} tidak terbaca: {pesan}', ['id' => $idAttachment, 'pesan' => $e->getMessage()]);

            throw new NotFoundException('Berkas lampiran tidak ditemukan.');
        }

        return ['lampiran' => self::untukKlien($row), 'isi' => $isi];
    }

    public function hapus(string $nip, int $idAttachment): void
    {
        $this->penjaga->selesaikan();

        $row  = $this->milik($nip, $idAttachment);
        $path = (string) ($row['path'] ?? '');

        if (! $this->model()->delete($idAttachment)) {
            throw new RuntimeException('Lampiran gagal dihapus.');
        }

        if ($path !== '') {
            // Berkas dihapus hanya bila baris benar-benar hilang setelah transaksi pemanggil selesai.
            $this->penjaga->catat($path, 'document_attachment', 'path');
        }

        if (! $this->penjaga->dalamTransaksi()) {
            $this->penjaga->selesaikan();
        }
    }

    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->koneksi()->table('document_attachment')
            ->where('NIP', $nip)
            ->where('id_riwayat', $idRiwayat)
            ->where('id_entri', (string) $idEntri)
            ->orderBy('id_attachment', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(self::untukKlien(...), $rows);
    }

    /**
     * Rekonsiliasi berkas dengan DB setelah transaksi pemanggil selesai (lihat PenjagaBerkas). Mengembalikan false bila
     * transaksi masih terbuka.
     */
    public function selesaikan(): bool
    {
        return $this->penjaga->selesaikan();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function untukKlien(array $row): array
    {
        return array_diff_key($row, array_flip(self::KOLOM_INTERNAL));
    }

    /**
     * Baris lampiran milik $nip (termasuk `path`); 404 bila tidak ada atau milik NIP lain.
     *
     * @return array<string, mixed>
     */
    private function milik(string $nip, int $idAttachment): array
    {
        $row = $this->koneksi()->table('document_attachment')
            ->where('id_attachment', $idAttachment)
            ->where('NIP', $nip)
            ->get()
            ->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(int $id): ?array
    {
        $row = $this->koneksi()->table('document_attachment')->where('id_attachment', $id)->get()->getRowArray();

        return is_array($row) ? self::untukKlien($row) : null;
    }

    private function hapusBerkasDiam(string $path): void
    {
        try {
            $this->storage->hapus($path);
        } catch (Throwable $e) {
            log_message('error', 'Berkas lampiran {path} gagal dihapus: {pesan}', ['path' => $path, 'pesan' => $e->getMessage()]);
        }
    }

    private function model(): DocumentAttachmentModel
    {
        return new DocumentAttachmentModel($this->koneksi());
    }

    private function koneksi(): ConnectionInterface
    {
        return $this->db ?? db_connect();
    }
}
