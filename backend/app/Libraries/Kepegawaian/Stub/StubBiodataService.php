<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Stub S0-A (MAKE-002). Implementasi nyata `App\Libraries\Kepegawaian\Pegawai\BiodataService` sejak MAKE-010 (dipasang
 * di Services.php); stub tetap fail-closed (BelumTersediaException → 501).
 */
final class StubBiodataService implements BiodataServiceInterface
{
    public function ubah(AuthContext $auth, string $nip, array $data, ?UploadedFile $foto = null): array
    {
        throw new BelumTersediaException('ubah biodata');
    }

    public function foto(AuthContext $auth, string $nip): array
    {
        throw new BelumTersediaException('foto pegawai');
    }
}
