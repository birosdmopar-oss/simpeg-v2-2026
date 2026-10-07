<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Stub fail-closed S0-A (MAKE-002) sampai implementasi nyata WS-2 (MAKE-009): setiap pemanggilan → 501.
 */
final class StubAttachmentService implements AttachmentServiceInterface
{
    private const FITUR = 'lampiran';

    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function ambil(string $nip, int $idAttachment): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function hapus(string $nip, int $idAttachment): void
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
    {
        throw new BelumTersediaException(self::FITUR);
    }
}
