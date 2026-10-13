<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Fake AttachmentService di memori untuk test (S0-A MAKE-002) — TIDAK pernah dipakai kode produksi dan tidak menulis
 * ke DB. Mengikuti kontrak: ekstensi dari ISI berkas (`guessExtension()`), batas ukuran AturanLampiran (422
 * `errors.berkas`), lampiran terikat NIP (404 bila bukan milik NIP itu), kunci baris = kolom DDL (`NIP` huruf besar).
 */
final class FakeAttachmentService implements AttachmentServiceInterface
{
    /**
     * @var array<int, array<string, mixed>> id_attachment => baris (kolom document_attachment)
     */
    public array $baris = [];

    /**
     * @var array<int, string> id_attachment => isi berkas
     */
    private array $isi = [];

    private int $idBerikut = 1;

    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
    {
        $ext = (string) $berkas->guessExtension();

        if ($ext === '' || ! $aturan->ekstensiDiizinkan($ext)) {
            throw new ValidationException('Validasi gagal.', ['berkas' => ['Jenis berkas tidak diizinkan.']]);
        }

        if ((int) $berkas->getSize() > $aturan->batasByte()) {
            throw new ValidationException('Validasi gagal.', ['berkas' => ["Ukuran berkas melebihi {$aturan->batasMb} MB."]]);
        }

        $id  = $this->idBerikut++;
        $row = [
            'id_attachment' => $id,
            'NIP'           => $nip,
            'id_riwayat'    => $idRiwayat,
            'id_entri'      => (string) $idEntri,
            'basename'      => "lampiran-{$id}.{$ext}",
            'display_name'  => $berkas->getClientName(),
            'file_size'     => (int) $berkas->getSize(),
            'file_ext'      => $ext,
        ];

        $this->baris[$id] = $row;
        $this->isi[$id]   = (string) file_get_contents($berkas->getTempName());

        return $row;
    }

    public function ambil(string $nip, int $idAttachment): array
    {
        return ['lampiran' => $this->milik($nip, $idAttachment), 'isi' => $this->isi[$idAttachment] ?? ''];
    }

    public function hapus(string $nip, int $idAttachment): void
    {
        $this->milik($nip, $idAttachment);

        unset($this->baris[$idAttachment], $this->isi[$idAttachment]);
    }

    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
    {
        return array_values(array_filter(
            $this->baris,
            static fn (array $row): bool => $row['NIP'] === $nip && $row['id_riwayat'] === $idRiwayat && $row['id_entri'] === (string) $idEntri,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function milik(string $nip, int $idAttachment): array
    {
        $row = $this->baris[$idAttachment] ?? null;

        if ($row === null || $row['NIP'] !== $nip) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return $row;
    }
}
