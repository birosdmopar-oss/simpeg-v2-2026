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
 * ke DB. Menegakkan ekstensi & batas ukuran AturanLampiran (422) seperti kontrak, sehingga test engine bisa menguji
 * jalur lampiran tanpa implementasi nyata WS-2.
 */
final class FakeAttachmentService implements AttachmentServiceInterface
{
    /**
     * @var array<int, array<string, mixed>> id_attachment => baris (snake_case = kolom document_attachment)
     */
    public array $baris = [];

    private int $idBerikut = 1;

    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
    {
        $ext = $berkas->getClientExtension();

        if (! $aturan->ekstensiDiizinkan($ext)) {
            throw new ValidationException('Validasi gagal.', ['lampiran' => ['Jenis berkas tidak diizinkan.']]);
        }

        if ((int) $berkas->getSize() > $aturan->batasByte()) {
            throw new ValidationException('Validasi gagal.', ['lampiran' => ["Ukuran berkas melebihi {$aturan->batasMb} MB."]]);
        }

        $id  = $this->idBerikut++;
        $row = [
            'id_attachment' => $id,
            'NIP'           => $nip,
            'id_riwayat'    => $idRiwayat,
            'id_entri'      => (string) $idEntri,
            'display_name'  => $berkas->getClientName(),
            'file_size'     => (int) $berkas->getSize(),
            'file_ext'      => strtolower($ext),
        ];

        $this->baris[$id] = $row;

        return $row;
    }

    public function hapus(int $idAttachment): void
    {
        if (! isset($this->baris[$idAttachment])) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        unset($this->baris[$idAttachment]);
    }

    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
    {
        return array_values(array_filter(
            $this->baris,
            static fn (array $row): bool => $row['NIP'] === $nip && $row['id_riwayat'] === $idRiwayat && $row['id_entri'] === (string) $idEntri,
        ));
    }
}
